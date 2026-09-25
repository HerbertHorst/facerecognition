<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\FaceRecognition\Helper;

use OCP\Files\File;
use OCP\ITempManager;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Service\FileService;

/**
 * Searches a region of a photo the user marked for faces: cuts the region, with
 * a margin, out of the original photo, scales it to what the model can take,
 * runs the detector on it, and gives the faces back in pixels of the original
 * photo.
 *
 * Why a crop helps even though the full photo was already analysed: the full
 * photo is downscaled to the model's maximum area before detection, so a small
 * face can fall below the detector's size threshold and be missed. The crop is
 * scaled to that same area, which for a region smaller than it means scaled
 * up, so the same face is large enough to be detected, and its descriptor is
 * comparable to descriptors from full-image detections (dlib aligns the face
 * before computing it).
 *
 * A face drawn by hand and a region to search again both go through here, so
 * that orientation, mime type and the way back to the coordinates of the photo
 * are handled once. What is done with the faces found is up to each of them.
 */
class ManualFaceDetector {

	/**
	 * Prefix of every log line about the faces and regions the user marked,
	 * so that they can be told apart from the ones of the rest of the app.
	 */
	public const LOG_PREFIX = '[manual faces] ';

	/** @var FileService */
	private $fileService;

	/** @var ITempManager */
	private $tempManager;

	public function __construct(FileService $fileService, ITempManager $tempManager) {
		$this->fileService = $fileService;
		$this->tempManager = $tempManager;
	}

	/**
	 * The faces the model finds in a region of a photo, with the margin given
	 * around it, in pixels of the original photo. The region is in the same
	 * pixels, in the frame the photo is shown in, after its orientation.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array<int, array{x: int, y: int, width: int, height: int, confidence: float, landmarks: array, descriptor: array}>
	 *
	 * @throws \RuntimeException if the photo cannot be read, or the region is not on it
	 */
	public function detect(IModel $model, string $userId, int $fileId, array $rect, int $marginX, int $marginY): array {
		$node = $this->fileService->getFileById($fileId, $userId);
		if (!($node instanceof File)) {
			throw new \RuntimeException('the file ' . $fileId . ' is not available');
		}

		$localPath = $this->fileService->getLocalFile($node);
		if ($localPath === null) {
			throw new \RuntimeException('the file ' . $fileId . ' cannot be read');
		}

		$crop = $this->cropRegion($localPath, $model->getPreferredMimeType(), $rect, $marginX, $marginY);

		// Reuse TempImage only for the max-area scale (memory safety) and mime
		// conversion. minImageSide is 1 on purpose: a face crop is meant to be
		// small, so it must not be skipped for being "too small".
		$tempImage = new TempImage(
			$crop['path'],
			$model->getPreferredMimeType(),
			$model->getMaximumArea(),
			1
		);
		try {
			$rawFaces = $model->detectFaces($tempImage->getTempPath());
			$ratio = $tempImage->getRatio();
		} finally {
			$tempImage->clean();
		}

		$faces = [];
		foreach ($rawFaces as $rawFace) {
			$faces[] = self::toOriginal($rawFace, $ratio, $crop['offsetX'], $crop['offsetY']);
		}
		return $faces;
	}

	/**
	 * Removes the temporary files of the last search: the crop, and the copy
	 * of a file that is not on local storage.
	 */
	public function clean(): void {
		$this->tempManager->clean();
		$this->fileService->clean();
	}

	/**
	 * The rectangle to cut out of a photo for a region: the region with the
	 * margin on every side, kept inside the photo. Null if nothing of the
	 * region is on the photo.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{x: int, y: int, width: int, height: int}|null
	 */
	public static function cropRect(int $imageWidth, int $imageHeight, array $rect, int $marginX, int $marginY): ?array {
		if ($imageWidth <= 0 || $imageHeight <= 0 || $rect['width'] <= 0 || $rect['height'] <= 0) {
			return null;
		}

		$left = max(0, $rect['x'] - $marginX);
		$top = max(0, $rect['y'] - $marginY);
		$right = min($imageWidth, $rect['x'] + $rect['width'] + $marginX);
		$bottom = min($imageHeight, $rect['y'] + $rect['height'] + $marginY);
		if ($right <= $left || $bottom <= $top) {
			return null;
		}

		return [
			'x' => $left,
			'y' => $top,
			'width' => $right - $left,
			'height' => $bottom - $top,
		];
	}

	/**
	 * Map a face detected on the crop back to original-image pixels. The
	 * detector runs on the (scaled) crop, so coordinates are scaled by the
	 * TempImage ratio and shifted by the crop offset, mirroring the
	 * normalization done for full-image detections. The landmarks go the same
	 * way, so that a face found here carries them like one of the analysis.
	 *
	 * @param array<string, mixed> $rawFace detection with left/top/right/bottom
	 *
	 * @return array{x: int, y: int, width: int, height: int, confidence: float, landmarks: array, descriptor: array}
	 */
	public static function toOriginal(array $rawFace, float $ratio, int $offsetX, int $offsetY): array {
		$left   = (int) round(((int) $rawFace['left'])   * $ratio) + $offsetX;
		$top    = (int) round(((int) $rawFace['top'])    * $ratio) + $offsetY;
		$right  = (int) round(((int) $rawFace['right'])  * $ratio) + $offsetX;
		$bottom = (int) round(((int) $rawFace['bottom']) * $ratio) + $offsetY;

		$landmarks = [];
		foreach ($rawFace['landmarks'] ?? [] as $landmark) {
			$landmarks[] = [
				'x' => (int) round($landmark['x'] * $ratio) + $offsetX,
				'y' => (int) round($landmark['y'] * $ratio) + $offsetY,
			];
		}

		return [
			'x'          => $left,
			'y'          => $top,
			'width'      => max(1, $right - $left),
			'height'     => max(1, $bottom - $top),
			'confidence' => (float) ($rawFace['detection_confidence'] ?? 0.0),
			'landmarks'  => $landmarks,
			'descriptor' => $rawFace['descriptor'] ?? [],
		];
	}

	/**
	 * A box as the edges FaceRect compares.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{left: int, right: int, top: int, bottom: int}
	 */
	public static function edges(array $rect): array {
		return [
			'left' => (int) $rect['x'],
			'right' => (int) $rect['x'] + (int) $rect['width'],
			'top' => (int) $rect['y'],
			'bottom' => (int) $rect['y'] + (int) $rect['height'],
		];
	}

	/**
	 * Crop the region (plus the margin) from the original image and save it to
	 * a temporary file. Coordinates are original-image pixels in the oriented
	 * frame, so the image is orientation-fixed before cropping.
	 *
	 * The crop offset is returned alongside the path so detections made on the
	 * crop can be mapped back to original-image pixels.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{path: string, offsetX: int, offsetY: int}
	 *
	 * @throws \RuntimeException if the photo cannot be loaded, or the region is not on it
	 */
	private function cropRegion(string $localPath, string $mimeType, array $rect, int $marginX, int $marginY): array {
		// The same loader the analysis uses, so that a face marked on a HEIC or
		// an AVIF is cropped from the same image the model saw. No maximum area
		// is imposed here: the rectangle is in pixels of the original image, and
		// a downscale would put the crop somewhere else.
		$image = ImageUtil::loadFromPath($localPath, true, null);
		if (is_null($image)) {
			throw new \RuntimeException('the photo cannot be loaded');
		}

		$crop = self::cropRect($image->width(), $image->height(), $rect, $marginX, $marginY);
		if (is_null($crop)) {
			throw new \RuntimeException('the region is not on the photo');
		}

		if ($image->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']) === false) {
			throw new \RuntimeException('the region cannot be cut out of the photo');
		}

		$cropPath = $this->tempManager->getTemporaryFile();
		if ($cropPath === false || $image->save($cropPath, $mimeType) === false) {
			throw new \RuntimeException('the region cannot be saved for the search');
		}

		return [
			'path'    => $cropPath,
			'offsetX' => $crop['x'],
			'offsetY' => $crop['y'],
		];
	}
}
