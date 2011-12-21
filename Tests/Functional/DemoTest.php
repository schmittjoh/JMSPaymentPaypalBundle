<?php

namespace JMS\Payment\PaypalBundle\Tests\Functional;

class DemoTest extends BaseTestCase
{
	public function testCreatePayment()
	{
	    $client = $this->createClient();
		$this->importDatabaseSchema();

        $client->request('GET', '/payment');
        $response = $client->getResponse();

        $this->assertEquals(302, $response->getStatusCode());
	}
}