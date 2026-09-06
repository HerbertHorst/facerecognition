<?php
/**
 * @copyright Copyright (c) 2021 Ming Tsang <nkming2@gmail.com>
 * @copyright Copyright (c) 2022 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Ming Tsang <nkming2@gmail.com>
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

namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\Files\File;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\ApiController as NCApiController;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

class ApiController extends NcApiController {

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

/** @var ClusterMapper */
	private $clusterMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var UrlService */
	private $urlService;

	/** @var string */
	private $userId;

	public function __construct(
		$AppName,
		IRequest        $request,
		FaceMapper      $faceMapper,
		ImageMapper     $imageMapper,
ClusterMapper   $clusterMapper,
	                            PersonMapper    $personmapper,
		SettingsService $settingsService,
		UrlService      $urlService,
		$UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
$this->clusterMapper  = $clusterMapper;
		$this->personMapper    = $personmapper;
		$this->settingsService = $settingsService;
		$this->urlService      = $urlService;
		$this->userId          = $UserId;
	}

	/**
	 * API V1
	 */

	/**
	 * Get all named persons
	 *
	 * - Endpoint: /persons
	 * - Method: GET
	 * - Response: Array of persons
	 * 		- Person:
	 * 			- name: Name of the person
	 * 			- thumbFaceId: Face representing this person
	 * 			- count: Number of images associated to this person
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function getPersons(): JSONResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new JSONResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findAll($this->userId, $modelId);
		foreach ($persons as $person) {
			$facesCount = 0;
			$thumbFaceId = null;
			foreach ($this->clustersOfName($person->getName()) as $cluster) {
				$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId);
				if (is_null($thumbFaceId) && !empty($clusterFaces)) {
					$thumbFaceId = $clusterFaces[0]->getId();
				}
				$facesCount += count($clusterFaces);
			}

			$respPerson = [];
			$respPerson['name'] = $person->getName();
			$respPerson['thumbFaceId'] = $thumbFaceId;
			$respPerson['count'] = $facesCount;

			$resp[] = $respPerson;
		}

		return new JSONResponse($resp);
	}

	/**
	 * Get all faces associated to a person
	 *
	 * - Endpoint: /person/<name>/faces
	 * - Method: GET
	 * - URL Arguments: name - (string) name of the person
	 * - Response: Array of faces
	 * 		- Face:
	 * 			- id: Face ID
	 * 			- fileId: The file where this face was found
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function getFacesByPerson(string $name): JSONResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new JSONResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->clustersOfName($name);
		foreach ($clusters as $cluster) {
			$faces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId);
			foreach ($faces as $face) {
				$image = $this->imageMapper->find($this->userId, $face->getImage());

				$respFace = [];
				$respFace['id'] = $face->getId();
				$respFace['fileId'] = $image->getFile();

				$resp[] = $respFace;
			}
		}

		return new JSONResponse($resp);
	}

	/**
	 * API V2
	 */

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getPersonsV2($thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$list = [];
		$modelId = $this->settingsService->getCurrentFaceModel();
		$personsNames = $this->personMapper->findAll($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$name = $personNamed->getName();
			$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $name, $modelId, 1));

			$person = [];
			$person['name'] = $name;
			$person['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);
			$person['count'] = $this->imageMapper->countFromPerson($this->userId, $modelId, $name);

			$list[] = $person;
		}

		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getPerson(string $personName, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		if (empty($personName))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$resp = [];
		$resp['name'] = $personName;
		$resp['thumbUrl'] = null;
		$resp['images'] = array();

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $personName, $modelId, 1));
		$resp['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);

		$images = $this->imageMapper->findFromPerson($this->userId, $modelId, $personName);
		foreach ($images as $image) {
			$node = $this->urlService->getFileNode($image->getFile());
			if ($node === null) continue;

			$photo = [];
			$photo['basename'] = $this->urlService->getBasename($node);
			$photo['filename'] = $this->urlService->getFilename($node);
			$photo['mimetype'] = $this->urlService->getMimetype($node);
			$photo['fileUrl']  = $this->urlService->getRedirectToFileUrl($node);
			$photo['thumbUrl'] = $this->urlService->getPreviewUrl($node, 256);

			$resp['images'][] = $photo;
		}

		return new JSONResponse($resp, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function updatePerson(string $personName, $name = null, $visible = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		if (empty($personName))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->clustersOfName($personName);
		if (empty($clusters))
			return new JSONResponse([], Http::STATUS_NOT_FOUND);

		if (!is_null($name)) {
			$person = $this->personMapper->findByName($this->userId, $personName);
			if (!is_null($person)) {
				$this->personMapper->rename($person->getId(), $name);
			}
		}

		// Hiding a cluster also forgets who it was, so the person can be left
		// without clusters and stop being a person.
		if (!is_null($visible)) {
			foreach ($clusters as $cluster) {
				$this->clusterMapper->setVisibility($cluster->getId(), (bool) $visible);
			}
			$this->personMapper->deleteOrphaned($this->userId);
		}

		// FIXME: What should response?
		if (is_null($name) || (!is_null($visible) && !$visible))
			return new JSONResponse([], Http::STATUS_OK);
		else
			return $this->getPerson($name);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function updateCluster(int $clusterId, $name = null, $visible = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$cluster = $this->clusterMapper->find($this->userId, $clusterId);

		if (!is_null($name)) {
			$person = $this->personMapper->findOrCreateByName($this->userId, $name);
			$this->clusterMapper->setPerson($cluster->getId(), $person->getId());
		}

		if (!is_null($visible)) {
			$this->clusterMapper->setVisibility($cluster->getId(), (bool) $visible);
		}

		$this->personMapper->deleteOrphaned($this->userId);
		$cluster = $this->clusterMapper->find($this->userId, $clusterId);

		// FIXME: What should response?
		return new JSONResponse($cluster, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function discoverPerson($minimum_count = NULL, $max_previews = 40, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$discoveries = [];

		$modelId = $this->settingsService->getCurrentFaceModel();
		if (is_null($minimum_count))
			$minimum_count = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minimum_count)
				continue;

			$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, $max_previews);

			$faces = [];
			foreach ($clusterFaces as $clusterFace) {
				$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$discovery = [];
			$discovery['id'] = $cluster->getId();
			$discovery['count'] = $clusterSize;
			$discovery['faces'] = $faces;

			$discoveries[] = $discovery;
		}

		usort($discoveries, function ($a, $b) {
			return $b['count'] <=> $a['count'];
		});

		return new JSONResponse($discoveries, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function autocomplete(string $query, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (strlen($query) < 3)
			return new JSONResponse([], Http::STATUS_OK);

		$resp = [];

		$modelId = $this->settingsService->getCurrentFaceModel();
		$persons = $this->personMapper->findLike($this->userId, $modelId, $query);
		foreach ($persons as $person) {
			$name = [];
			$name['name'] = $person->getName();
			$name['value'] = $person->getName();

			$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $person->getName(), $modelId, 1));
			$name['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);

			$resp[] = $name;
		}

		return new JSONResponse($resp, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function detachFace(int $faceId, $name = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$face = $this->faceMapper->find($faceId);

		$personId = null;
		if (!is_null($name) && $name !== '') {
			$personId = $this->personMapper->findOrCreateByName($this->userId, $name)->getId();
		}

		$cluster = $this->clusterMapper->detachFace($face->getCluster(), $faceId, $personId);

		return new JSONResponse($cluster, Http::STATUS_OK);
	}


	/**
	 * The clusters of the person of that name, which are the different ways
	 * their face was found.
	 *
	 * @return \OCA\FaceRecognition\Db\Cluster[]
	 */
	private function clustersOfName(string $name): array {
		$person = $this->personMapper->findByName($this->userId, $name);
		if (is_null($person)) {
			return [];
		}

		return $this->clusterMapper->findByPerson($this->userId,
			$this->settingsService->getCurrentFaceModel(), $person->getId());
	}

	/**
	 * The faces of one file, so that a client can draw the boxes that are
	 * already there before the user adds one by hand.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getFacesForFile(int $fileId): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$faces = $this->faceMapper->findFromFile($this->userId, $modelId, $fileId);

		$resp = [];
		foreach ($faces as $face) {
			$resp[] = [
				'id'         => $face->getId(),
				'x'          => $face->getX(),
				'y'          => $face->getY(),
				'width'      => $face->getWidth(),
				'height'     => $face->getHeight(),
				'cluster'    => $face->getCluster(),
				'personName' => $this->nameOfCluster($face->getCluster()),
				'isManual'   => (bool) $face->getIsManual(),
			];
		}

		return new JSONResponse($resp, Http::STATUS_OK);
	}

	/**
	 * Adds a face the user drew on a photo and says who it is.
	 *
	 * The rectangle comes as fractions (0..1) of the photo, and imageWidth and
	 * imageHeight are its natural pixel size, so that the face is stored in the
	 * same pixel coordinates as the ones the model finds.
	 *
	 * The face gets a cluster of its own, pointing at the person the user
	 * named. That is what the data model is for: a person has as many clusters
	 * as different ways their face was found, and one more of them costs
	 * nothing. It also keeps the face out of the clusters the analysis built,
	 * so a hand drawn box never becomes the reason another face joins them.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function addManualFace(
		int    $fileId,
		string $personName,
		float  $x,
		float  $y,
		float  $width,
		float  $height,
		int    $imageWidth,
		int    $imageHeight,
		bool   $useForClustering = false
	): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (trim($personName) === '')
			return new JSONResponse(['error' => 'personName must not be empty'], Http::STATUS_BAD_REQUEST);
		if ($imageWidth <= 0 || $imageHeight <= 0)
			return new JSONResponse(['error' => 'invalid image dimensions'], Http::STATUS_BAD_REQUEST);
		if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 ||
		    ($x + $width) > 1.0001 || ($y + $height) > 1.0001)
			return new JSONResponse(['error' => 'invalid rectangle'], Http::STATUS_BAD_REQUEST);

		// Turn the fractional rectangle into pixels of the original image, and
		// refuse the ones that round down to no area at all.
		$pxX      = (int) round($x * $imageWidth);
		$pxY      = (int) round($y * $imageHeight);
		$pxWidth  = (int) round($width * $imageWidth);
		$pxHeight = (int) round($height * $imageHeight);
		if ($pxWidth < 1 || $pxHeight < 1)
			return new JSONResponse(['error' => 'rectangle too small'], Http::STATUS_BAD_REQUEST);

		// The file has to exist and be one of this user's. getFileNode() looks
		// it up in their own storage, so an id of somebody else, or one that is
		// not there at all, is rejected before anything is written.
		if ($this->urlService->getFileNode($fileId) === null)
			return new JSONResponse(['error' => 'file not found or not accessible'], Http::STATUS_NOT_FOUND);

		$modelId = $this->settingsService->getCurrentFaceModel();

		// A face needs an image row, and the file may never have been analyzed.
		$image = $this->imageMapper->findFromFile($this->userId, $modelId, $fileId);
		if ($image === null) {
			$image = new Image();
			$image->setUser($this->userId);
			$image->setFile($fileId);
			$image->setModel($modelId);
			$image->setIsProcessed(false);
			$image = $this->imageMapper->insert($image);
		}

		$person = $this->personMapper->findOrCreateByName($this->userId, $personName);

		$clusterId = $this->clusterMapper->create($this->userId, $modelId);
		$this->clusterMapper->setPerson($clusterId, $person->getId());

		$face = new Face();
		$face->setImage($image->getId());
		$face->setCluster($clusterId);
		$face->setX($pxX);
		$face->setY($pxY);
		$face->setWidth($pxWidth);
		$face->setHeight($pxHeight);
		$face->setConfidence(1.0);
		$face->landmarks = [];
		$face->descriptor = [];
		$face->isGroupable = $useForClustering;
		$face->isManual = true;
		$face->setCreationTime(new \DateTime());

		$face = $this->faceMapper->insertManualFace($face);

		// When the user asked for the face to be used for recognition it is
		// stored groupable but without a descriptor, so it takes no part in the
		// clustering yet. ManualFaceDescriptorTask crops the marked region, and
		// if it finds a face there it computes the descriptor. From then on the
		// face is a sample of its cluster like any other, and faces that look
		// alike join the person the user named.
		return new JSONResponse([
			'faceId'           => $face->getId(),
			'clusterId'        => $clusterId,
			'personId'         => $person->getId(),
			'name'             => $person->getName(),
			'clusteringQueued' => $useForClustering,
		], Http::STATUS_OK);
	}

	/**
	 * Says who one already detected face is, without touching the other faces
	 * of its cluster.
	 *
	 * The face is taken out of its cluster and put in one of the person the
	 * user named, which is what detachFace() does, and it is marked as manual
	 * so that analyzing the file again does not delete it and undo the change.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function reassignFace(int $faceId, string $personName): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (trim($personName) === '')
			return new JSONResponse(['error' => 'personName must not be empty'], Http::STATUS_BAD_REQUEST);

		$face = $this->faceMapper->find($faceId);
		if ($face === null)
			return new JSONResponse(['error' => 'face not found'], Http::STATUS_NOT_FOUND);

		// The image of the face has to be one of this user's.
		$image = $this->imageMapper->find($this->userId, $face->getImage());
		if ($image === null)
			return new JSONResponse([], Http::STATUS_FORBIDDEN);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$person = $this->personMapper->findOrCreateByName($this->userId, $personName);

		// The cluster is null while the clustering has not reached this face yet,
		// and no id is ever zero, so this is the one case with nothing to detach
		// the face from.
		$currentCluster = (int) $face->getCluster();
		if ($currentCluster === 0) {
			$clusterId = $this->clusterMapper->create($this->userId, $modelId);
			$this->clusterMapper->setPerson($clusterId, $person->getId());
			$this->clusterMapper->attachFaces([$faceId], $clusterId);
		} else {
			$clusterId = $this->clusterMapper
				->detachFace($currentCluster, $faceId, $person->getId())
				->getId();
		}

		$this->faceMapper->markFaceManual($faceId);

		return new JSONResponse([
			'faceId'    => $faceId,
			'clusterId' => $clusterId,
			'personId'  => $person->getId(),
			'name'      => $person->getName(),
		], Http::STATUS_OK);
	}

	/**
	 * The name the user gave the cluster, if they gave it one.
	 *
	 * @param int|null $clusterId
	 */
	private function nameOfCluster($clusterId): ?string {
		if (is_null($clusterId)) {
			return null;
		}

		try {
			$cluster = $this->clusterMapper->findById((int) $clusterId);
			$personId = $cluster->getPerson();
			if (is_null($personId)) {
				return null;
			}

			return $this->personMapper->find($this->userId, (int) $personId)->getName();
		} catch (\Exception $e) {
			// The cluster or the person is gone, so there is no name to give.
			return null;
		}
	}

}
