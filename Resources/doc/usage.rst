Usage
=====

.. tip ::

    If you're not familiar with *JMSPaymentCoreBundle*, we recommend you follow the `Accepting Payments`_ guide, which shows how to integrate the bundle into your application.

.. _Accepting Payments: http://jmspaymentcorebundle.readthedocs.io/en/stable/accepting_payments.html#depositing-money

.. code-block :: php

    // src/AppBundle/Controller/FooController.php

    use JMS\Payment\CoreBundle\Form\ChoosePaymentMethodType;

    $config = [
        'paypal_express_checkout' => [
            'return_url' => 'https://example.com/return-url',
            'cancel_url' => 'https://example.com/cancel-url',
            'useraction' => 'commit',
        ],
    ];

    $form = $this->createForm(ChoosePaymentMethodType::class, null, [
        'amount'          => 10.00,
        'currency'        => 'EUR',
        'predefined_data' => $config,
    ]);

.. note ::

    The ``return_url`` and ``cancel_url`` options are required but ``useraction`` is optional. However, it is usually set to ``commit``. See below for more information on each of these options.

.. _usage-available-options:

Available options
-----------------
This section describes all available options. Certain options can be set globally, in the bundle configuration, in which case they apply to all payments:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        foo: bar

However, globally-defined options will be overriden if specified in a certain payment. In the following example, the ``foo`` option will have the value ``baz`` instead of the globally-defined ``bar``:

.. code-block :: php

    $config = [
        'paypal_express_checkout' => [
            'foo' => 'baz',
        ],
    ];

    $form = $this->createForm(ChoosePaymentMethodType::class, null, [
        'amount'          => 10.00,
        'currency'        => 'EUR',
        'predefined_data' => $config,
    ]);

``return_url``
~~~~~~~~~~~~~~
**Mandatory**

The URL to which the user is redirected once they authorize the payment on PayPal's website.

This is usually the URL of the same controller action that redirected the user to PayPal (see `Depositing Money`_ in JMSPaymentCoreBundle's documentation):

.. _Depositing Money: http://jmspaymentcorebundle.readthedocs.io/en/stable/accepting_payments.html#depositing-money

.. code-block :: php

    use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

    $config = [
        'paypal_express_checkout' => [
            'return_url' => $this->generateUrl('app_orders_paymentcreate', [
                'id' => $order->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ],
    ];

Alternatively, you can set it globally, through the bundle's configuration:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        return_url: https://example.com/return-url

``cancel_url``
~~~~~~~~~~~~~~
**Mandatory**

The URL to which the user is redirected when they cancel the payment on PayPal's website.

.. code-block :: php

    use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

    $config = [
        'paypal_express_checkout' => [
            'cancel_url' => $this->generateUrl('app_orders_paymentcancel', [
                'id' => $order->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ],
    ];

Alternatively, you can set it globally, through the bundle's configuration:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        cancel_url: https://example.com/cancel-url

``notify_url``
~~~~~~~~~~~~~~
**Optional**

**Default**: ``null``

The URL to which Instant Payment Notifications (IPN) will be sent.

.. code-block :: php

    use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

    $config = [
        'paypal_express_checkout' => [
            'notify_url' => $this->generateUrl('app_orders_ipn', [
                'id' => $order->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ],
    ];

Alternatively, you can set it globally, through the bundle's configuration:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        notify_url: https://example.com/notify-url

``useraction``
~~~~~~~~~~~~~~
**Optional**

**Default**: ``null``

The ``useraction`` option determines whether buyers complete their purchase on PayPal or on your website. See `PayPal's documentation <https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECCustomizing/>`__ (*Allowing buyers to complete their purchases on PayPal* section) for more information.

Usually, this option is set to ``commit``:

.. code-block :: php

    $config = [
        'paypal_express_checkout' => [
            'useraction' => 'commit',
        ],
    ];

Since it will usually apply to all payments, you can set it globally:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        useraction: commit


``checkout_params``
~~~~~~~~~~~~~~~~~~~
**Optional**

**Default**: ``[]``

Allows you to pass additional information to PayPal, for example, shipping information. See `PayPal's documentation <https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/>`__ for all available options.

.. code-block :: php

    $config = [
        'paypal_express_checkout' => [
            'checkout_params' => [
                'PAYMENTREQUEST_0_SHIPTONAME' => 'John Doe',
            ],
        ],
    ];

``debug``
~~~~~~~~~
**Optional**

**Default**: ``%kernel.debug%``

Whether to use the PayPal's Sandbox or the Live site. By default this is set to ``kernel.debug`` so it will use the Sandbox in development and the Live site in production, which is normally what you want.

If for some reason you need to change this behaviour, you can set it globally:

.. code-block :: yaml

    # app/config/config.yml

    jms_payment_paypal:
        debug: true # Use the Sandbox
