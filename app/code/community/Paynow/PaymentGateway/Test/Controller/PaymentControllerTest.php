<?php

class Paynow_PaymentGateway_Test_Controller_PaymentControllerTest extends EcomDev_PHPUnit_Test_Case_Controller
{
    public function testCancelActionRedirectsToCartOnFailure()
    {
        $this->dispatch('paynow/payment/cancel');

        $this->assertRedirectTo('checkout/cart');
    }

    public function testCancelActionEmptyOrderId()
    {
        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '');
        $request->setParam('key', 'some_key');

        $this->dispatch('paynow/payment/cancel');

        $this->assertRedirectTo('checkout/cart');
    }

    public function testCancelActionEmptyKey()
    {
        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', 'some_id');
        $request->setParam('key', '');

        $this->dispatch('paynow/payment/cancel');

        $this->assertRedirectTo('checkout/cart');
    }

    public function testCancelActionWrongKey()
    {
        $order = $this->mockModel('sales/order', ['getId', 'getQuoteId', 'getStoreId', 'loadByIncrementId'])->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);

        $this->replaceByMock('model', 'sales/order', $order);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '0000001');
        $request->setParam('key', 'not_real_key');

        $this->dispatch('paynow/payment/cancel');

        $this->assertRedirectTo('checkout/cart');
    }

    public function testCancelAction()
    {
        $order = $this->mockModel('sales/order', ['getId', 'getQuoteId', 'getStoreId', 'loadByIncrementId', 'getPayment'])->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);


        $paymentMock = $this->mockModel(
            'sales/payment',
            [
                'getLastTransId',
            ]
        )->getMock();

        $paymentMock->expects($this->once())->method('getLastTransId')->willReturn(1);

        $order->method('getPayment')->willReturn($paymentMock);

        $this->replaceByMock('model', 'sales/order', $order);

        $transactionCollection = $this->mockModel('sales/order_payment_transaction', ['getCollection', 'addAttributeToFilter', 'getSize'])->getMock();
        $transactionCollection->method('getCollection')->willReturn($transactionCollection);
        $transactionCollection->method('addAttributeToFilter')->willReturn($transactionCollection);
        $transactionCollection->method('getSize')->willReturn(0);

        $payment = $this->mockModel('paynow/payment', ['getApiSignature', '_updatePaymentStatusCanceled'])->getMock();
        $payment->method('getApiSignature')->willReturn('signature');
        $payment->expects($this->once())->method('_updatePaymentStatusCanceled')->with($paymentMock, 1, true);

        $this->replaceByMock('model', 'paynow/payment', $payment);

        $key = $payment->getOrderIdempotencyKey($order);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '0000001');
        $request->setParam('key', $key);

        $this->dispatch('paynow/payment/cancel');

        $this->assertRedirectTo('checkout/cart');
    }

    public function testCompleteActionFailure()
    {
        $session = $this->mockSession('core/session', ['getQuote']);
        $cart = $this->mockModel('checkout/cart', ['setIsActive', 'save']);
        $cart->method('setIsActive')->willReturnSelf();
        $session->expects($this->once())->method('getQuote')->willReturn($cart);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('paymentStatus', 'SOME_RANDOM_STATUS');

        $this->dispatch('paynow/payment/complete');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testCompleteActionSuccessPending()
    {
        $session = $this->mockSession('core/session', ['getQuote']);
        $cart = $this->mockModel('checkout/cart', ['setIsActive', 'save']);
        $cart->method('setIsActive')->willReturnSelf();
        $session->expects($this->once())->method('getQuote')->willReturn($cart);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('paymentStatus', 'PENDING');

        $this->dispatch('paynow/payment/complete');

        $this->assertRedirectTo('checkout/onepage/success');
    }

    public function testCompleteActionSuccessConfirmed()
    {
        $session = $this->mockSession('core/session', ['getQuote']);
        $cart = $this->mockModel('checkout/cart', ['setIsActive', 'save']);
        $cart->method('setIsActive')->willReturnSelf();
        $session->expects($this->once())->method('getQuote')->willReturn($cart);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('paymentStatus', 'CONFIRMED');

        $this->dispatch('paynow/payment/complete');

        $this->assertRedirectTo('checkout/onepage/success');
    }

    public function testNewActionRedirectsToFailureOnEmptyOrderId()
    {
        $this->dispatch('paynow/payment/new');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testNewAction()
    {
        $session = $this->mockModel(
            'core/session',
            [
                'getLastRealOrderId'
            ]
        )->getMock();

        $session->expects($this->once())->method('getLastRealOrderId')->willReturn('0000001');

        $this->replaceByMock('model', 'core/session', $session);

        $order = $this->mockModel(
            'sales/order',
            [
                'loadByIncrementId',
                'getId'
            ]
        )->getMock();

        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);
        $order->expects($this->once())->method('getId')->willReturn(1);

        $this->replaceByMock('model', 'sales/order', $order);

        $payment = $this->mockModel('paynow/payment', [
            'createOrder'
        ])->getMock();

        $result = new \Paynow\Response\Payment\Authorize('https://example.com', '', '');
        $payment->expects($this->once())->method('createOrder')->with($order)->willReturn($result);

        $this->replaceByMock('model', 'paynow/payment', $payment);

        $this->dispatch('paynow/payment/new');

        $this->assertRedirectToUrl('https://example.com');
    }

    public function testNotifyAction()
    {
        $model = $this->getModelMock('paynow/payment', ['processNotification']);
        $model->expects($this->once())->method('processNotification');

        $this->replaceByMock('model', 'paynow/payment', $model);

        $this->dispatch('paynow/payment/notify');
    }

    public function testRetryActionRedirectsToCartOnFailure()
    {
        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testRetryActionEmptyOrderId()
    {
        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '');
        $request->setParam('key', 'some_key');

        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testRetryActionEmptyKey()
    {
        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', 'some_id');
        $request->setParam('key', '');

        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testRetryActionWrongKey()
    {
        $order = $this->mockModel('sales/order', ['getId', 'getQuoteId', 'getStoreId', 'loadByIncrementId'])->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);

        $this->replaceByMock('model', 'sales/order', $order);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '0000001');
        $request->setParam('key', 'not_real_key');

        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testRetryActionWrongStatus()
    {
        $order = $this->mockModel('sales/order', ['getId', 'getQuoteId', 'getStoreId', 'loadByIncrementId', 'getPayment'])->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);

        $payment = $this->getModelMock('paynow/payment', ['getAdditionalInformation']);
        $payment->method('getAdditionalInformation')->willReturn('SOME_RANDOM_STATUS');

        $order->method('getPayment')->willReturn($payment);

        $this->replaceByMock('model', 'sales/order', $order);

        $key = $payment->getOrderIdempotencyKey($order);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '0000001');
        $request->setParam('key', $key);

        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectTo('checkout/onepage/failure');
    }

    public function testRetryAction()
    {
        $order = $this->mockModel('sales/order', ['getId', 'getQuoteId', 'getStoreId', 'loadByIncrementId', 'getPayment'])->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->expects($this->once())->method('loadByIncrementId')->with('0000001')->willReturn($order);

        $paymentMock = $this->getModelMock('paynow/payment', ['getAdditionalInformation']);
        $paymentMock->method('getAdditionalInformation')->willReturn('REJECTED');

        $order->method('getPayment')->willReturn($paymentMock);

        $this->replaceByMock('model', 'sales/order', $order);

        $transactionCollection = $this->mockModel('sales/order_payment_transaction', ['getCollection', 'addAttributeToFilter', 'getSize'])->getMock();
        $transactionCollection->method('getCollection')->willReturn($transactionCollection);
        $transactionCollection->method('addAttributeToFilter')->willReturn($transactionCollection);
        $transactionCollection->method('getSize')->willReturn(0);

        $payment = $this->mockModel('paynow/payment', ['getApiSignature', 'createOrder'])->getMock();
        $payment->method('getApiSignature')->willReturn('signature');

        $result = new \Paynow\Response\Payment\Authorize('https://example.com', '', '');
        $payment->expects($this->once())->method('createOrder')->with($order, true)->willReturn($result);

        $this->replaceByMock('model', 'paynow/payment', $payment);

        $key = $payment->getOrderIdempotencyKey($order);

        $request = $this->getRequest();
        $request->setMethod('GET');
        $request->setParam('order_id', '0000001');
        $request->setParam('key', $key);

        $this->dispatch('paynow/payment/retry');

        $this->assertRedirectToUrl('https://example.com');
    }
}