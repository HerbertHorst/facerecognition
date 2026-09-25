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
namespace OCA\FaceRecognition\Tests\Unit;

use OCP\Files\File;

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualFaceDescriptorTask;

/**
 * The search of a marking for a descriptor, with a model whose finds the test
 * decides, so that what is stored can be compared with what was found.
 *
 * The marking is drawn at 40,40 with 50 x 50 pixels, so with the margin of
 * 40% the crop is 20,20 to 110,110, and a find at 20,20 to 70,70 of the crop is
 * exactly the drawn box.
 */
class ManualFaceDescriptorTaskUnitTest extends ManualFaceTaskTestCase {

	public function setUp(): void {
		parent::setUp();
		// The crop is 90 x 90, analyzed at its own size.
		$this->model->method('getMaximumArea')->willReturn(90 * 90);
	}

	private function task(): ManualFaceDescriptorTask {
		return new ManualFaceDescriptorTask($this->faceMapper, $this->fileService, $this->settingsService, $this->modelManager, $this->tempManager);
	}

	private static function pending(int $id, int $fileId = 500): array {
		return ['id' => $id, 'file' => $fileId, 'x' => 40, 'y' => 40, 'width' => 50, 'height' => 50];
	}

	private function migratedWith(array $pending): void {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesPendingDescriptor')->with(self::USER, 1)->willReturn($pending);
	}

	/**
	 * The marking takes the box, the descriptor and the confidence the
	 * detector gave the face, and not an invented one.
	 */
	public function testTheFoundFaceIsStoredWithTheConfidenceOfTheDetector() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 0.42, [0.5, 0.6])]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, [0.5, 0.6], 40, 40, 50, 50, 0.42, false);
		$this->faceMapper->expects($this->never())->method('markManualFaceNotGroupable');

		$this->assertTrue($this->run($this->task()));
	}

	/**
	 * When the detector puts the box somewhere else than where it was drawn,
	 * that is recorded, so the user can be told the box moved.
	 */
	public function testABoxThatMovedIsRecorded() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		// A face in the upper left corner of the margin, nowhere near the box.
		$this->modelFinds([self::rawFace(0, 0, 18, 18, 1.02)]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 20, 20, 18, 18, 1.02, true);

		$this->run($this->task());
	}

	/**
	 * A box that stays where it was drawn is not reported as moved.
	 */
	public function testABoxThatStayedIsNotRecordedAsMoved() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		// A little tighter than drawn, but the same face.
		$this->modelFinds([self::rawFace(24, 24, 66, 66, 1.02)]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 44, 44, 42, 42, 1.02, false);

		$this->run($this->task());
	}

	/**
	 * Of several faces in the crop, the biggest one is taken.
	 */
	public function testTheBiggestFaceIsTaken() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([
			self::rawFace(0, 0, 10, 10, 1.1),
			self::rawFace(20, 20, 70, 70, 1.0),
		]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 40, 40, 50, 50, 1.0, false);

		$this->run($this->task());
	}

	/**
	 * Without a face in the crop the marking records that there is none, and
	 * no descriptor is made up.
	 */
	public function testNoFaceIsRecorded() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([]);

		$this->faceMapper->expects($this->never())->method('setManualFaceDescriptor');
		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);

		$this->assertTrue($this->run($this->task()));
	}

	/**
	 * A marking whose file cannot be read is recorded as having no face, so
	 * that it is not taken again on every run, and the next one is searched.
	 */
	public function testAnUnreadableFileIsRecordedAndTheNextMarkingIsSearched() {
		$this->migratedWith([self::pending(7, 404), self::pending(8, 500)]);
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId) {
			return $fileId === 404 ? null : $this->createMock(File::class);
		});
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);

		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);
		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(8, $this->anything(), 40, 40, 50, 50, 1.0, false);

		$this->assertTrue($this->run($this->task()));
		$this->assertLogged('[manual faces] Manual face 7 on file 404');
	}

	/**
	 * An Error, and not only an Exception, of a single marking stays with that
	 * marking: the background job would stop the whole run on anything that
	 * leaves a task.
	 */
	public function testAnErrorOfOneMarkingStaysWithIt() {
		$this->migratedWith([self::pending(7), self::pending(8)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)], [self::rawFace(20, 20, 70, 70, 1.0)]);

		$stored = [];
		$this->faceMapper->method('setManualFaceDescriptor')
			->willReturnCallback(function (int $faceId) use (&$stored) {
				if ($faceId === 7) {
					throw new \TypeError('broken');
				}
				$stored[] = $faceId;
			});
		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);

		$this->assertTrue($this->run($this->task()));
		$this->assertEquals([8], $stored);
	}

	/**
	 * An Error outside of a single marking, here reading what is pending,
	 * ends this task and nothing else: it returns like it always does.
	 */
	public function testAnErrorOfTheTaskItselfDoesNotLeaveIt() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesPendingDescriptor')->willThrowException(new \Error('no such table'));

		$this->assertTrue($this->run($this->task()));
		$this->assertLogged('[manual faces] The faces marked by hand could not be searched');
	}

	/**
	 * Even recording that a marking failed can fail; the other markings are
	 * searched all the same.
	 */
	public function testAFailureToRecordAFailureDoesNotStopTheOthers() {
		$this->migratedWith([self::pending(7, 404), self::pending(8, 500)]);
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId) {
			return $fileId === 404 ? null : $this->createMock(File::class);
		});
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$this->faceMapper->method('markManualFaceNotGroupable')->willThrowException(new \RuntimeException('read only'));

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')->with(8);

		$this->assertTrue($this->run($this->task()));
	}

	/**
	 * Before the migration ran there is no state to find the pending markings
	 * by, and the task steps aside, saying why.
	 */
	public function testBeforeTheMigrationTheTaskIsSkipped() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->faceMapper->expects($this->never())->method('findManualFacesPendingDescriptor');
		$this->model->expects($this->never())->method('open');

		$this->assertTrue($this->run($this->task()));
		$this->assertLogged('migration');
	}

	/**
	 * The model is only opened when there is something to search.
	 */
	public function testTheModelIsNotOpenedWithoutWork() {
		$this->migratedWith([]);
		$this->model->expects($this->never())->method('open');

		$this->assertTrue($this->run($this->task()));
	}
}
