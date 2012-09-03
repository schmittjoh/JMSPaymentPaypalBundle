============
Installation
============
Dependencies
------------
This plugin depends on the JMSPaymentCoreBundle_, so you'll need to add this to your kernel
as well even if you don't want to use its persistence capabilities.

Configuration
-------------
::

    // YAML
    jms_payment_paypal:
        username: your api username (not your account username)
        password: your api password (not your account password)
        signature: your api signature


=====
Usage
=====
With the Payment Plugin Controller (Recommended)
------------------------------------------------
http://jmsyst.com/bundles/JMSPaymentCoreBundle/master/usage

Without the Payment Plugin Controller
-------------------------------------
The Payment Plugin Controller is made available by the CoreBundle and basically is the 
interface to a persistence backend like the Doctrine ORM. It also performs additional 
integrity checks to validate transactions. If you don't need these checks, and only want 
an easy way to communicate with the Paypal API, then you can use the plugin directly::

    $plugin = $container->get('payment.plugin.paypal_express_checkout');

.. _JMSPaymentCoreBundle: https://github.com/schmittjoh/JMSPaymentCoreBundle/blob/master/Resources/doc/index.rst

Sandbox environment
-------------------
Connections are automatically made to PayPal's Sandbox environment when the Symfony 
kernel is running in debug mode. In non-debug mode, connections are made to PayPal's production 
environment.
