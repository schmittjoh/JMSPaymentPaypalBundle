<?php

namespace JMS\Payment\PaypalBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseTestCase extends WebTestCase
{
	static protected function createKernel(array $options = array())
	{
		return new AppKernel(
			isset($options['config']) ? $options['config'] : 'default.yml'
		);
	}
	
	protected final function importDatabaseSchema()
	{
        $em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if (!empty($metadata)) {
        	$schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        	$schemaTool->createSchema($metadata);
        }
	}
}