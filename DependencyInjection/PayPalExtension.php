<?php

namespace Bundle\JMS\Payment\PayPalPaymentBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/*
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

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