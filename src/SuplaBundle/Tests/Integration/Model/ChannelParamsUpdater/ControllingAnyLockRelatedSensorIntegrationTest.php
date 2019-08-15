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

namespace SuplaBundle\Tests\Integration\Model\ChannelParamsUpdater;

use SuplaBundle\Entity\IODevice;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\Enums\ChannelType;
use SuplaBundle\Model\ChannelParamsUpdater\ChannelParamsUpdater;
use SuplaBundle\Tests\Integration\IntegrationTestCase;
use SuplaBundle\Tests\Integration\Traits\SuplaApiHelper;

/**
 * Application allows to pair CONTROLLING* and OPENING* channels with each other so it knows which channel it should use to display the
 * CONTROLLING* channel's state.
 *
 * The rules as as follows:
 *  - ID of the paired OPENING* sensor is saved into param2 of the CONTROLLING* channel
 *  - ID of the paired CONTROLLING* channel is saved into param1 of the OPENING* sensor
 *  - when any of the side initiates the change, the other one should be updated automatically
 *  - if any of the side is changed for different paired channel, the old one should be cleared
 *
 * The whole logic of pairing the channels is implemented in ControllingAnyLockRelatedSensor class. Its subclasses are responsible for
 * allowing appropriate OPENING* sensors with corresponding CONTROLLING* channel functions.
 *
 * The whole functionality is tested below.
 */
class ControllingAnyLockRelatedSensorIntegrationTest extends IntegrationTestCase {
    use SuplaApiHelper;

    /** @var IODevice */
    private $device;
    /** @var ChannelParamsUpdater */
    private $updater;

    /** @before */
    public function createDeviceForTests() {
        $user = $this->createConfirmedUser();
        $location = $this->createLocation($user);
        $this->device = $this->createDevice($location, [
            [ChannelType::RELAY, ChannelFunction::CONTROLLINGTHEDOORLOCK],
            [ChannelType::SENSORNC, ChannelFunction::OPENINGSENSOR_DOOR],
            [ChannelType::SENSORNC, ChannelFunction::OPENINGSENSOR_DOOR],
            [ChannelType::SENSORNC, ChannelFunction::OPENINGSENSOR_GATE],
        ]);
        $this->updater = self::$container->get(ChannelParamsUpdater::class);
        $this->simulateAuthentication($user);
    }

    public function testSettingOpeningSensorForChannel() {
        $channel = $this->device->getChannels()[0];
        $this->updater->updateChannelParams($channel, new IODeviceChannelWithParams(0, $this->device->getChannels()[1]->getId()));
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals($channel->getId(), $this->device->getChannels()[1]->getParam1());
        $this->assertEquals($this->device->getChannels()[1]->getId(), $this->device->getChannels()[0]->getParam2());
    }

    public function testSettingChannelForOpeningSensor() {
        $sensor = $this->device->getChannels()[1];
        $this->updater->updateChannelParams($sensor, new IODeviceChannelWithParams($this->device->getChannels()[0]->getId()));
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals($sensor->getId(), $this->device->getChannels()[0]->getParam2());
        $this->assertEquals($this->device->getChannels()[0]->getId(), $this->device->getChannels()[1]->getParam1());
    }

    public function testChangingOpeningSensorForChannelClearsPreviousSelection() {
        // pair 0 & 3
        $this->updater->updateChannelParams(
            $this->device->getChannels()[0],
            new IODeviceChannelWithParams(0, $this->device->getChannels()[1]->getId())
        );
        // pair 0 & 4
        $this->updater->updateChannelParams(
            $this->device->getChannels()[0],
            new IODeviceChannelWithParams(0, $this->device->getChannels()[2]->getId())
        );
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals($this->device->getChannels()[2]->getId(), $this->device->getChannels()[0]->getParam2());
        $this->assertEquals($this->device->getChannels()[0]->getId(), $this->device->getChannels()[2]->getParam1());
        // sensor 3 should not be connected
        $this->assertEquals(0, $this->device->getChannels()[1]->getParam1());
    }

    public function testClearingOpeningSensorForChannelClearsBothConnections() {
        // pair 0 & 3
        $this->updater->updateChannelParams(
            $this->device->getChannels()[0],
            new IODeviceChannelWithParams(0, $this->device->getChannels()[1]->getId())
        );
        // unpair 0
        $this->updater->updateChannelParams($this->device->getChannels()[0], new IODeviceChannelWithParams());
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals(0, $this->device->getChannels()[0]->getParam2());
        $this->assertEquals(0, $this->device->getChannels()[1]->getParam1());
    }

    public function testClearingOpeningSensorIfWrongIdIsInDevice() {
        $this->device->getChannels()[0]->setParam2(1234);
        $this->getEntityManager()->persist($this->device->getChannels()[0]);
        // unpair invalid channel
        $this->updater->updateChannelParams($this->device->getChannels()[0], new IODeviceChannelWithParams());
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals(0, $this->device->getChannels()[0]->getParam2());
    }

    public function testTryingToPairInvalidChannelsIsNotSuccessful() {
        $this->updater->updateChannelParams(
            $this->device->getChannels()[0],
            new IODeviceChannelWithParams(0, $this->device->getChannels()[3]->getId())
        );
        $this->getEntityManager()->refresh($this->device);
        $this->assertEquals(0, $this->device->getChannels()[0]->getParam2());
        $this->assertEquals(0, $this->device->getChannels()[3]->getParam1());
    }
}
