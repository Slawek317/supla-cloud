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

use SuplaBundle\Entity\AuditEntry;
use SuplaBundle\Entity\User;
use SuplaBundle\Enums\AuditedEvent;
use SuplaBundle\Repository\AuditEntryRepository;
use SuplaBundle\Supla\SuplaAutodiscoverMock;
use SuplaBundle\Supla\SuplaServerMock;
use SuplaBundle\Tests\Integration\IntegrationTestCase;
use SuplaBundle\Tests\Integration\TestClient;
use SuplaBundle\Tests\Integration\TestMailer;
use SuplaBundle\Tests\Integration\Traits\ResponseAssertions;
use SuplaBundle\Tests\Integration\Traits\SuplaApiHelper;
use SuplaBundle\Tests\Integration\Traits\TestTimeProvider;

class UserControllerIntegrationTest extends IntegrationTestCase {
    use SuplaApiHelper;
    use ResponseAssertions;

    /** @var User */
    private $user;

    protected function initializeDatabaseForTests() {
        $this->user = $this->createConfirmedUser();
    }

    public function testDeletingUserAccountWithInvalidPasswordFails() {
        SuplaAutodiscoverMock::clear(false);
        /** @var TestClient $client */
        $client = self::createAuthenticatedClient();
        $client->apiRequest('PATCH', '/api/users/current', ['action' => 'delete', 'password' => 'xxx']);
        $response = $client->getResponse();
        $this->assertStatusCode(400, $response);
        $this->assertNotNull($this->getEntityManager()->find(User::class, $this->user->getId()));
        $this->assertEmpty(TestMailer::getMessages());
    }

    /** @depends testDeletingUserAccountWithInvalidPasswordFails */
    public function testDeletingUserAccountWithNoPasswordFails() {
        SuplaAutodiscoverMock::clear(false);
        /** @var TestClient $client */
        $client = self::createAuthenticatedClient();
        $client->apiRequest('PATCH', '/api/users/current', ['action' => 'delete']);
        $response = $client->getResponse();
        $this->assertStatusCode(400, $response);
        $this->assertNotNull($this->getEntityManager()->find(User::class, $this->user->getId()));
        $this->assertEmpty(TestMailer::getMessages());
    }

    /** @depends testDeletingUserAccountWithNoPasswordFails */
    public function testDeletingUserAccount() {
        /** @var TestClient $client */
        $client = self::createAuthenticatedClient();
        $client->apiRequest('PATCH', '/api/users/current', ['action' => 'delete', 'password' => 'supla123']);
        $response = $client->getResponse();
        $this->assertStatusCode(204, $response);
        $this->assertNotNull($this->user = $this->getEntityManager()->find(User::class, $this->user->getId()));
        $this->assertNotEmpty(TestMailer::getMessages());
        $confirmationMessage = TestMailer::getMessages()[0];
        $this->assertArrayHasKey($this->user->getEmail(), $confirmationMessage->getTo());
        $this->assertContains('Removal', $confirmationMessage->getSubject());
        $this->assertContains($this->user->getToken(), $confirmationMessage->getBody());
    }

    /** @depends testDeletingUserAccount */
    public function testDeletingWithBadToken() {
        $client = $this->createHttpsClient();
        $client->apiRequest('PATCH', 'api/confirm-deletion/aslkjfdalskdjflkasdflkjalsjflaksdjflkajsdfjlkasndfkansdlj');
        $this->assertStatusCode(404, $client->getResponse());
        $this->assertNotNull($this->getEntityManager()->find(User::class, $this->user->getId()));
    }

    /** @depends testDeletingWithBadToken */
    public function testDeletingWithGoodToken() {
        $client = $this->createHttpsClient();
        $this->user = $this->getEntityManager()->find(User::class, $this->user->getId());
        $client->apiRequest('PATCH', 'api/confirm-deletion/' . $this->user->getToken());
        $this->assertStatusCode(204, $client->getResponse());
        $this->getDoctrine()->resetEntityManager();
        $this->assertNull($this->getEntityManager()->find(User::class, $this->user->getId()));
    }

    /** @depends testDeletingWithGoodToken */
    public function testDeletingUserAccountReconnectsSuplaServer() {
        $this->assertContains('USER-RECONNECT:1', SuplaServerMock::$executedCommands);
    }

    /** @depends testDeletingWithGoodToken */
    public function testDeletingUserAccountEventIsSavedInAudit() {
        /** @var AuditEntry $lastEntry */
        $entries = self::$container->get(AuditEntryRepository::class)->findAll();
        $lastEntry = end($entries);
        $this->assertEquals(AuditedEvent::USER_ACCOUNT_DELETED, $lastEntry->getEvent()->getId());
        $this->assertEquals(1, $lastEntry->getIntParam());
        $this->assertEquals('supler@supla.org', $lastEntry->getTextParam());
    }

    public function testCannotDeleteAfterOneHour() {
        $this->testDeletingUserAccount();
        TestTimeProvider::setTime('+61 minutes');
        $client = $this->createHttpsClient();
        $this->user = $this->getEntityManager()->find(User::class, $this->user->getId());
        $client->apiRequest('PATCH', 'api/confirm-deletion/' . $this->user->getToken());
        $this->assertStatusCode(404, $client->getResponse());
        $this->assertNotNull($this->getEntityManager()->find(User::class, $this->user->getId()));
    }
}
