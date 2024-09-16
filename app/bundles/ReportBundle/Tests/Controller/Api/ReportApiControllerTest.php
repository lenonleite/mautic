<?php

namespace Mautic\ReportBundle\Tests\Controller\Api;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\ReportBundle\Entity\Report;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\Response;

class ReportApiControllerTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

//    /**
//     * Testing in a single method to decrease execution time from DB overhead.
//     */
//    public function testPostGetPatchPutDeleteEndPoints(): void
//    {
//        // Create a new report
//        $data = json_decode(file_get_contents(__DIR__.'/data/post.json'), true);
//        $this->client->request('POST', '/api/reports/new', $data);
//        $response     = $this->client->getResponse();
//        $responseData = json_decode($response->getContent(), true);
//        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
//        $this->assertTrue(isset($responseData['report']));
//        $this->assertEquals($data['name'], $responseData['report']['name']);
//        $id     = $responseData['report']['id'];
//        $source = $data['source'];
//
//        // Get the new report
//        $this->client->restart();
//        $this->client->request('GET', sprintf('/api/reports/%s', $id));
//        $response = $this->client->getResponse();
//        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
//        $responseData = json_decode($response->getContent(), true);
//        $this->assertTrue(isset($responseData['data']));
//        $this->assertTrue(isset($responseData['dataColumns']));
//        $this->assertTrue(isset($responseData['report']));
//        $this->assertEquals($data['name'], $responseData['report']['name']);
//
//        // Patch a report
//        $data = json_decode(file_get_contents(__DIR__.'/data/patch.json'), true);
//        $this->client->request('PATCH', sprintf('/api/reports/%s/edit', $id), $data);
//        $response = $this->client->getResponse();
//        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
//        $responseData = json_decode($response->getContent(), true);
//        $this->assertTrue(isset($responseData['report']));
//        $this->assertEquals($source, $responseData['report']['source']);
//        $this->assertEquals($data['scheduleUnit'], $responseData['report']['scheduleUnit']);
//        $this->assertEquals($data['toAddress'], $responseData['report']['toAddress']);
//        $this->assertEquals($data['scheduleDay'], $responseData['report']['scheduleDay']);
//
//        // PUT a report
//        $data = json_decode(file_get_contents(__DIR__.'/data/put.json'), true);
//        $this->client->request('PUT', sprintf('/api/reports/%s/edit', $id), $data);
//        $response = $this->client->getResponse();
//        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
//        $responseData = json_decode($response->getContent(), true);
//        $this->assertTrue(isset($responseData['report']));
//        $this->assertEquals($data['name'], $responseData['report']['name']);
//        $this->assertEquals($data['source'], $responseData['report']['source']);
//        $this->assertEquals($data['scheduleUnit'], $responseData['report']['scheduleUnit']);
//        $this->assertEquals($data['toAddress'], $responseData['report']['toAddress']);
//        $this->assertEquals($data['scheduleDay'], $responseData['report']['scheduleDay']);
//        $this->assertEmpty($responseData['report']['filters']);
//
//        // DELETE a report
//        $this->client->request('DELETE', sprintf('/api/reports/%s/delete', $id), $data);
//        $response = $this->client->getResponse();
//        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
//        $this->assertTrue(isset($responseData['report']));
//        $this->assertEquals($data['name'], $responseData['report']['name']);
//        $this->client->request('GET', sprintf('/api/reports/%s', $id), $data);
//        $response = $this->client->getResponse();
//        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
//    }

    public function testGetReportFailByNoCorrectAccess(): void
    {
        $password = 'Maut1cR0cks!!!!!';
        $idReport = $this->createReportData();
        $role     = $this->createRole(false);
        $user     = $this->createUser($role, $password);
        $this->em->flush();
        $this->em->detach($role);
        // Disable the default logging in via username and password.
        $this->clientServer = [];
        $this->setUpSymfony($this->configParams);
        $this->loginUser($user->getUsername());
        $this->client->setServerParameter('PHP_AUTH_USER', $user->getUsername());
        $this->client->setServerParameter('PHP_AUTH_PW', $password);
        $this->client->request('GET', '/api/reports/'.$idReport);
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function createUser(Role $role, string $password='mautic'): User
    {
        $user = new User();
        $user->setFirstName('John');
        $user->setLastName('Doe');
        $user->setUsername('john.doe');
        $user->setEmail('john.doe@email.com');
        $encoder = static::getContainer()->get('security.encoder_factory')->getEncoder($user);
        $user->setPassword($encoder->encodePassword($password, null));
        $user->setRole($role);

        $this->em->persist($user);

        return $user;
    }

    private function createRole(bool $isAdmin = false): Role
    {
        $role = new Role();
        $role->setName('Role');
        $role->setIsAdmin($isAdmin);

        $this->em->persist($role);

        return $role;
    }

    private function createReportData(): int
    {
        $report = new Report();
        $report->setName('Contact report');
        $report->setDescription('<b>This is allowed HTML</b>');
        $report->setSource('leads');
        $coulmns = [
            'l.firstname',
            'l.lastname',
            'l.email',
            'l.date_added',
        ];
        $report->setColumns($coulmns);

        $this->getContainer()->get('mautic.report.model.report')->saveEntity($report);

        return $report->getId();
    }
}
