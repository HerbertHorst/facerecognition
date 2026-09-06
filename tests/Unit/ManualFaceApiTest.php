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

use Test\TestCase;

use OCP\IRequest;
use OCP\Files\File;
use OCP\AppFramework\Http;

use OCA\FaceRecognition\Controller\ApiController;

use OCA\FaceRecognition\Db\Cluster;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * Validation and edge case coverage of the manual face endpoints
 * (addManualFace / reassignFace). The collaborators are mocked and real
 * entities are used as fixtures, since their getters are magic __call methods
 * that a PHPUnit mock cannot stub, so what is exercised here is the logic of
 * the controller alone and no database is touched.
 */
class ManualFaceApiTest extends TestCase {

	private const USER = 'alice';

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
	/** @var ApiController */
	private $controller;

	public function setUp(): void {
		parent::setUp();

		$request               = $this->createMock(IRequest::class);
		$this->faceMapper      = $this->createMock(FaceMapper::class);
		$this->imageMapper     = $this->createMock(ImageMapper::class);
		$this->clusterMapper   = $this->createMock(ClusterMapper::class);
		$this->personMapper    = $this->createMock(PersonMapper::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->urlService      = $this->createMock(UrlService::class);

		$this->controller = new ApiController(
			'facerecognition',
			$request,
			$this->faceMapper,
			$this->imageMapper,
			$this->clusterMapper,
			$this->personMapper,
			$this->settingsService,
			$this->urlService,
			self::USER
		);
	}

	private function enableUser(): void {
		$this->settingsService->method('getUserEnabled')->willReturn(true);
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
	}

	private function makePerson(int $id, string $name): Person {
		$person = new Person();
		$person->setId($id);
		$person->setName($name);
		return $person;
	}

	private function makeCluster(int $id, ?int $personId = null): Cluster {
		$cluster = new Cluster();
		$cluster->setId($id);
		$cluster->setUser(self::USER);
		$cluster->setModel(1);
		$cluster->setPerson($personId);
		return $cluster;
	}

	// --- addManualFace ----------------------------------------------------

	public function testAddManualFaceRejectsDisabledUser() {
		$this->settingsService->method('getUserEnabled')->willReturn(false);
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $resp->getStatus());
	}

	public function testAddManualFaceRejectsEmptyName() {
		$this->enableUser();
		$resp = $this->controller->addManualFace(42, '   ', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsInvalidImageDimensions() {
		$this->enableUser();
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 0, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsOutOfBoundsRectangle() {
		$this->enableUser();
		// x + width = 1.6, which spills outside the right edge of the image.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.8, 0.1, 0.8, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsZeroAreaBox() {
		$this->enableUser();
		// width 0.001 of a 100px image rounds down to 0 pixels -> degenerate face.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.001, 0.2, 100, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertEquals('rectangle too small', $resp->getData()['error']);
	}

	public function testAddManualFaceRejectsInaccessibleFile() {
		$this->enableUser();
		$this->urlService->method('getFileNode')->willReturn(null);
		$resp = $this->controller->addManualFace(999, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testAddManualFaceHappyPathQueuesClusteringWhenRequested() {
		$this->enableUser();

		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);

		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Alice'));

		// The face gets a cluster of its own, pointing at the person.
		$this->clusterMapper->method('create')->willReturn(7);
		$this->clusterMapper->expects($this->once())->method('setPerson')->with(7, 5);

		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) {
				$face->setId(100);
				return $face;
			});

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, true);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertEquals(7, $data['clusterId']);
		$this->assertEquals(5, $data['personId']);
		$this->assertEquals('Alice', $data['name']);
		// useForClustering=true: the face is queued for the background descriptor
		// task, which will decide whether a face is actually there.
		$this->assertTrue($data['clusteringQueued']);
	}

	public function testAddManualFaceStoresPixelsOfTheOriginalImage() {
		$this->enableUser();

		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->method('create')->willReturn(7);

		$inserted = null;
		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) use (&$inserted) {
				$face->setId(100);
				$inserted = $face;
				return $face;
			});

		$this->controller->addManualFace(42, 'Alice', 0.25, 0.5, 0.2, 0.1, 800, 600, false);

		$this->assertNotNull($inserted);
		$this->assertEquals(200, $inserted->getX());
		$this->assertEquals(300, $inserted->getY());
		$this->assertEquals(160, $inserted->getWidth());
		$this->assertEquals(60, $inserted->getHeight());
		$this->assertEquals(7, $inserted->getCluster());
		$this->assertTrue((bool) $inserted->getIsManual());
	}

	public function testAddManualFaceDoesNotQueueClusteringByDefault() {
		$this->enableUser();

		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);

		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->method('create')->willReturn(7);

		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) {
				$face->setId(100);
				return $face;
			});

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertFalse($resp->getData()['clusteringQueued']);
	}

	// --- reassignFace -----------------------------------------------------

	public function testReassignFaceRejectsEmptyName() {
		$this->enableUser();
		$resp = $this->controller->reassignFace(100, '  ');
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testReassignFaceReturnsNotFoundForMissingFace() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn(null);
		$resp = $this->controller->reassignFace(404, 'Alice');
		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testReassignFaceForbidsForeignImage() {
		$this->enableUser();

		$face = new Face();
		$face->setImage(77);
		$this->faceMapper->method('find')->willReturn($face);

		// Image does not belong to the current user.
		$this->imageMapper->method('find')->willReturn(null);

		$resp = $this->controller->reassignFace(100, 'Alice');
		$this->assertEquals(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testReassignFaceDetachesItFromItsCluster() {
		$this->enableUser();

		$face = new Face();
		$face->setImage(10);
		$face->setCluster(3);
		$this->faceMapper->method('find')->willReturn($face);

		$this->imageMapper->method('find')->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Bob'));

		// The face leaves its cluster and lands in one of the named person.
		$this->clusterMapper->expects($this->once())
			->method('detachFace')
			->with(3, 100, 5)
			->willReturn($this->makeCluster(8, 5));

		// And it is marked manual, so re-analyzing the file does not undo it.
		$this->faceMapper->expects($this->once())
			->method('markFaceManual')
			->with(100);

		$resp = $this->controller->reassignFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertEquals(8, $data['clusterId']);
		$this->assertEquals(5, $data['personId']);
		$this->assertEquals('Bob', $data['name']);
	}

	public function testReassignFaceWithoutClusterGetsANewOne() {
		$this->enableUser();

		// The clustering has not reached this face yet.
		$face = new Face();
		$face->setImage(10);
		$this->faceMapper->method('find')->willReturn($face);

		$this->imageMapper->method('find')->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Bob'));

		$this->clusterMapper->expects($this->never())->method('detachFace');
		$this->clusterMapper->method('create')->willReturn(9);
		$this->clusterMapper->expects($this->once())->method('setPerson')->with(9, 5);
		$this->clusterMapper->expects($this->once())->method('attachFaces')->with([100], 9);
		$this->faceMapper->expects($this->once())->method('markFaceManual')->with(100);

		$resp = $this->controller->reassignFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(9, $resp->getData()['clusterId']);
	}
}
