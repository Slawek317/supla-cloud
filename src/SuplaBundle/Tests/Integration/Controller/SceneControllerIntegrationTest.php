<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Tests\Integration\Controller;

use SuplaBundle\Entity\IODevice;
use SuplaBundle\Entity\IODeviceChannelGroup;
use SuplaBundle\Entity\User;
use SuplaBundle\Enums\ActionableSubjectType;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\Enums\ChannelFunctionAction;
use SuplaBundle\Enums\ChannelType;
use SuplaBundle\Tests\Integration\IntegrationTestCase;
use SuplaBundle\Tests\Integration\Traits\ResponseAssertions;
use SuplaBundle\Tests\Integration\Traits\SuplaApiHelper;
use Symfony\Component\HttpFoundation\Response;

class SceneControllerIntegrationTest extends IntegrationTestCase {
    use SuplaApiHelper;
    use ResponseAssertions;

    /** @var User */
    private $user;
    /** @var IODevice */
    private $device;
    /** @var IODeviceChannelGroup */
    private $channelGroup;

    protected function initializeDatabaseForTests() {
        $this->user = $this->createConfirmedUser();
        $location = $this->createLocation($this->user);
        $this->device = $this->createDevice($location, [
            [ChannelType::RELAY, ChannelFunction::LIGHTSWITCH],
            [ChannelType::RELAY, ChannelFunction::LIGHTSWITCH],
            [ChannelType::DIMMERANDRGBLED, ChannelFunction::DIMMERANDRGBLIGHTING],
        ]);
        $this->channelGroup = new IODeviceChannelGroup($this->user, $location, [
            $this->device->getChannels()[0],
            $this->device->getChannels()[1],
        ]);
        $this->getEntityManager()->persist($this->channelGroup);
        $this->getEntityManager()->flush();
    }

    private function createScene(array $data = []): Response {
        $data = array_merge([
            'caption' => 'My scene',
            'enabled' => true,
        ], $data);
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('POST', '/api/scenes', $data);
        return $client->getResponse();
    }

    public function testCreatingScene() {
        $response = $this->createScene();
        $this->assertStatusCode(201, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['enabled']);
        $this->assertEquals('My scene', $content['caption']);
        $this->assertEmpty($content['operationsIds']);
        return $content;
    }

    /** @depends testCreatingScene */
    public function testGettingSceneDetails($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('GET', '/api/scenes/' . $id . '?include=subject,operations');
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertEquals('My scene', $content['caption']);
        $this->assertEmpty($content['operationsIds']);
        $this->assertEmpty($content['operations']);
    }

    /** @depends testCreatingScene */
    public function testUpdatingSceneDetails($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id, [
            'caption' => 'My scene 2',
            'enabled' => false,
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertEquals('My scene 2', $content['caption']);
        $this->assertFalse($content['enabled']);
        $this->assertEmpty($content['operationsIds']);
    }

    /** @depends testCreatingScene */
    public function testAddingOperationsToScene($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $this->device->getChannels()[0]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::TURN_ON,
                ],
                [
                    'subjectId' => $this->device->getChannels()[1]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::TURN_OFF,
                    'delayMs' => 1000,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertCount(2, $content['operations']);
        $operation = $content['operations'][0];
        $this->assertEquals($this->device->getChannels()[0]->getId(), $operation['subjectId']);
        $this->assertEquals($this->device->getChannels()[0]->getId(), $operation['subject']['id']);
        $this->assertEquals(ActionableSubjectType::CHANNEL, $operation['subjectType']);
        $this->assertEquals(ChannelFunctionAction::TURN_ON, $operation['actionId']);
        $this->assertNull($operation['actionParam']);
        $this->assertEquals(0, $operation['delayMs']);
        $operation = $content['operations'][1];
        $this->assertEquals($this->device->getChannels()[1]->getId(), $operation['subjectId']);
        $this->assertEquals($this->device->getChannels()[1]->getId(), $operation['subject']['id']);
        $this->assertEquals(ActionableSubjectType::CHANNEL, $operation['subjectType']);
        $this->assertEquals(ChannelFunctionAction::TURN_OFF, $operation['actionId']);
        $this->assertNull($operation['actionParam']);
        $this->assertEquals(1000, $operation['delayMs']);
        return $sceneDetails;
    }

    /** @depends testCreatingScene */
    public function testUpdatingSceneMultipleTimes(array $sceneDetails) {
        $this->testAddingOperationsToScene($sceneDetails);
        $this->testAddingOperationsToScene($sceneDetails);
        $this->testAddingOperationsToScene($sceneDetails);
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('GET', '/api/scenes/' . $sceneDetails['id'] . '?include=subject,operations');
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content['operations']);
    }

    /** @depends testCreatingScene */
    public function testAddingOperationsWithParamsToScene($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $this->device->getChannels()[2]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::SET_RGBW_PARAMETERS,
                    'actionParam' => ['brightness' => 55],
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertCount(1, $content['operations']);
        $operation = $content['operations'][0];
        $this->assertEquals($this->device->getChannels()[2]->getId(), $operation['subjectId']);
        $this->assertEquals($this->device->getChannels()[2]->getId(), $operation['subject']['id']);
        $this->assertEquals(ActionableSubjectType::CHANNEL, $operation['subjectType']);
        $this->assertEquals(ChannelFunctionAction::SET_RGBW_PARAMETERS, $operation['actionId']);
        $this->assertEquals(['brightness' => 55], $operation['actionParam']);
        $this->assertEquals(0, $operation['delayMs']);
    }

    /** @depends testAddingOperationsToScene */
    public function testGettingSceneDetailsWithOperations($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('GET', '/api/scenes/' . $id . '?include=subject,operations');
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertCount(2, $content['operationsIds']);
        $this->assertCount(2, $content['operations']);
    }

    /** @depends testCreatingScene */
    public function testAddingOperationsWithChannelAndChannelGroupToScene($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $this->device->getChannels()[0]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::TURN_ON,
                ],
                [
                    'subjectId' => $this->channelGroup->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL_GROUP,
                    'actionId' => ChannelFunctionAction::TURN_ON,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(200, $response);
        $content = json_decode($response->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertCount(2, $content['operations']);
        $operation = $content['operations'][1];
        $this->assertEquals($this->channelGroup->getId(), $operation['subjectId']);
        $this->assertEquals(ActionableSubjectType::CHANNEL_GROUP, $operation['subjectType']);
    }

    /** @depends testCreatingScene */
    public function testAddingInvalidActionToOperation($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $this->device->getChannels()[0]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::OPEN,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(400, $response);
    }

    /** @depends testCreatingScene */
    public function testAddingInvalidActionParamToOperation($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $this->device->getChannels()[2]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::SET_RGBW_PARAMETERS,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(400, $response);
    }

    /** @depends testCreatingScene */
    public function testAddingInvalidSubjectParamToOperation($sceneDetails) {
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => 666,
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::TURN_ON,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(404, $response);
    }

    /** @depends testCreatingScene */
    public function testAddingNotMineChannelToOperation($sceneDetails) {
        $user = $this->createConfirmedUser('another@supla.org');
        $location = $this->createLocation($user);
        $device = $this->createDevice($location, [[ChannelType::RELAY, ChannelFunction::LIGHTSWITCH]]);
        $id = $sceneDetails['id'];
        $client = $this->createAuthenticatedClient($this->user);
        $client->apiRequestV23('PUT', '/api/scenes/' . $id . '?include=operations,subject', [
            'caption' => 'My scene',
            'enabled' => true,
            'operations' => [
                [
                    'subjectId' => $device->getChannels()[0]->getId(),
                    'subjectType' => ActionableSubjectType::CHANNEL,
                    'actionId' => ChannelFunctionAction::TURN_ON,
                ],
            ],
        ]);
        $response = $client->getResponse();
        $this->assertStatusCode(404, $response);
    }
}
