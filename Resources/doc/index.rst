============
Installation
============
Dependencies
------------
This plugin depends on the PaymentCoreBundle_, so you'll need to add this to your kernel
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
See the DemoController that is part of the PaymentCoreBundle_ for a sample usage:
https://github.com/schmittjoh/PaymentCoreBundle/blob/master/Controller/DemoController.php

Without the Payment Plugin Controller
-------------------------------------
The Payment Plugin Controller is made available by the CoreBundle and basically is the 
interface to a persistence backend like the Doctrine ORM. It also performs additional 
integrity checks to validate transactions. If you don't need these checks, and only want 
an easy way to communicate with the Paypal API, then you can use the plugin directly::

    $plugin = $container->get('payment.plugin.paypal_express_checkout');

.. _PaymentCoreBundle: http://github.com/schmittjoh/PaymentCoreBundle/Resources/doc/index.rst
