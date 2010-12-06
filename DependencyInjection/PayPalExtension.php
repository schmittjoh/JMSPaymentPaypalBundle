<?php

namespace Bundle\PayPalPaymentBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class PayPalExtension extends Extension
{
    public function configLoad($config, ContainerBuilder $container)
    {
		$xmlLoader = new XmlFileLoader($container, __DIR__.'/../Resources/config');
		$xmlLoader->load('services.xml');
        
        if (isset($config['username'])) {
            $container->setParameter('payment.paypal.username', $config['username']);
        }
        if (isset($config['password'])) {
            $container->setParameter('payment.paypal.password', $config['password']);
        }
        if (isset($config['signature'])) {
            $container->setParameter('payment.paypal.signature', $config['signature']);
        }
        if (isset($config['return_url'])) {
            $container->setParameter('payment.paypal.express_checkout.return_url', $config['return_url']);
        }
        if (isset($config['cancel_url'])) {
            $container->setParameter('payment.paypal.express_checkout.cancel_url', $config['cancel_url']);
        }
    }
    
	public function getNamespace()
	{
		return 'http://www.symfony-project.org/schema/dic/paypal';
	}
	
	public function getXsdValidationBasePath()
	{
		return __DIR__.'/../Resources/config/schema';
	}
	
	public function getAlias()
	{
		return 'paypal';
	}
}