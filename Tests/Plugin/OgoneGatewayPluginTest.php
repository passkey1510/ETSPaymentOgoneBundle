<?php

namespace ETS\Payment\OgoneBundle\Tests\Plugin;

use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Entity\FinancialTransaction;
use JMS\Payment\CoreBundle\Entity\Payment;
use JMS\Payment\CoreBundle\Entity\ExtendedData;
use JMS\Payment\CoreBundle\Entity\PaymentInstruction;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;

use ETS\Payment\OgoneBundle\Plugin\OgoneGatewayPluginMock;
use ETS\Payment\OgoneBundle\Tools\ShaIn;
use ETS\Payment\OgoneBundle\Plugin\Configuration\Redirection;
use ETS\Payment\OgoneBundle\Plugin\Configuration\Design;

/*
 * Copyright 2013 ETSGlobal <e4-devteam@etsglobal.org>
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

/**
 * Sha-1 In tool
 *
 * @author ETSGlobal <e4-devteam@etsglobal.org>
 */
class OgoneGatewayPluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @return array
     */
    public function provideTestTestRequestUrls()
    {
        return array(
            array(true, false, 'getStandardOrderUrl', 'https://secure.ogone.com/ncol/test/orderstandard.asp'),
            array(false, false, 'getStandardOrderUrl', 'https://secure.ogone.com/ncol/prod/orderstandard.asp'),
            array(false, true, 'getStandardOrderUrl', 'https://secure.ogone.com/ncol/prod/orderstandard_utf8.asp'),
            array(true, false, 'getDirectQueryUrl', 'https://secure.ogone.com/ncol/test/querydirect.asp'),
            array(false, false, 'getDirectQueryUrl', 'https://secure.ogone.com/ncol/prod/querydirect.asp'),
            array(false, true, 'getDirectQueryUrl', 'https://secure.ogone.com/ncol/prod/querydirect_utf8.asp'),
        );
    }

    /**
     * @param boolean $debug    Debug mode
     * @param boolean $utf8     UTF8 mode
     * @param string  $method   Methd to test
     * @param string  $expected Expected result
     *
     * @dataProvider provideTestTestRequestUrls
     */
    public function testTestRequestUrls($debug, $utf8, $method, $expected)
    {
        $plugin = $this->createPluginMock($debug, $utf8);

        $reflectionMethod = new \ReflectionMethod('ETS\Payment\OgoneBundle\Plugin\OgoneGatewayPlugin', $method);
        $reflectionMethod->setAccessible(true);

        $this->assertEquals($expected, $reflectionMethod->invoke($plugin));
    }

    /**
     * @param boolean $debug
     */
    public function testNewTransactionRequiresAnAction()
    {
        $plugin = $this->createPluginMock(true);

        $transaction = $this->createTransaction(42, 'EUR');
        $transaction->getExtendedData()->set('lang', 'en_US');

        try {
            $plugin->approveAndDeposit($transaction, 42);

            $this->fail('Plugin was expected to throw an exception.');
        } catch (ActionRequiredException $ex) {

            $action = $ex->getAction();

            if (!$action instanceof \JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl) {
                $this->fail('The exception should contain a VisitUrl action.');
            }

            $this->assertRegExp('#https://secure.ogone.com/ncol/test/orderstandard.asp\?AMOUNT=4200&CN=Foo\+Bar&CURRENCY=EUR&LANGUAGE=en_US&ORDERID=.*&SHASIGN=.*#', $action->getUrl());
        }

        $transaction->setState(FinancialTransactionInterface::STATE_PENDING);
        $transaction->setReasonCode('action_required');
        $transaction->setResponseCode('pending');

        return $transaction;
    }

    /**
     * @param boolean $debug
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException
     * @expectedExceptionMessage User must authorize the transaction
     */
    public function testApproveRequiresAnActionForNewTransactions()
    {
        $plugin = $this->createPluginMock(true);

        $transaction = $this->createTransaction(42, 'EUR');
        $transaction->getExtendedData()->set('lang', 'en_US');

        $plugin->approve($transaction, 42);
    }

    /**
     * @param boolean $debug
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException
     * @expectedExceptionMessage User must authorize the transaction
     */
    public function testDepositRequiresAnActionForNewTransactions()
    {
        $plugin = $this->createPluginMock(true);

        $transaction = $this->createTransaction(42, 'EUR');
        $transaction->getExtendedData()->set('lang', 'en_US');

        $plugin->deposit($transaction, 42);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     */
    public function testApproveAndDepositTrigerApproveAndDeposit(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'deposited');

        $plugin->approveAndDeposit($transaction, false);

        $this->assertEquals(42, $transaction->getProcessedAmount());
        $this->assertEquals('success', $transaction->getResponseCode());
        $this->assertEquals('none', $transaction->getReasonCode());
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException
     * @expectedExceptionMessage Payment is still approving, status: 51.
     */
    public function testApprovingTransaction(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'approving');

        $plugin->approve($transaction, false);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     */
    public function testApprovedTransaction(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'approved');

        $plugin->approve($transaction, false);

        $this->assertEquals(42, $transaction->getProcessedAmount());
        $this->assertEquals('success', $transaction->getResponseCode());
        $this->assertEquals('none', $transaction->getReasonCode());
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\PaymentPendingException
     * @expectedExceptionMessage Payment is still pending, status: 91.
     */
    public function testDepositingTransaction(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'depositing');

        $plugin->deposit($transaction, false);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     */
    public function testDepositedTransaction(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'deposited');

        $plugin->deposit($transaction, false);

        $this->assertEquals(42, $transaction->getProcessedAmount());
        $this->assertEquals('success', $transaction->getResponseCode());
        $this->assertEquals('none', $transaction->getReasonCode());
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage Payment status "8" is not valid for approvment
     */
    public function testApproveWithUnknowStateGenerateAnException(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'not_managed');

        $plugin->approve($transaction, false);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage Payment status "8" is not valid for depositing
     */
    public function testDepositWithUnknowStateGenerateAnException(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'not_managed');

        $plugin->deposit($transaction, false);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage Ogone-Response was not successful: Some of the data entered is incorrect. Please retry.
     */
    public function testInvalidStateGenerateAnException(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, 'invalid');

        $plugin->deposit($transaction, false);
    }

    /**
     * @param FinancialTransaction $transaction
     *
     * @depends testNewTransactionRequiresAnAction
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException
     * @expectedExceptionMessage The API request was not successful (Status: 500):
     */
    public function testSendApiRequestFail(FinancialTransaction $transaction)
    {
        $plugin = $this->createPluginMock(true, false, '500');

        $plugin->approve($transaction, false);
    }

    /**
     * @expectedException \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     * @expectedExceptionMessage The payment instruction is invalid.
     */
    public function testInvalidCheckPaymentInstruction()
    {
        $plugin = $this->createPluginMock(true, false, 'not_managed');
        $transaction = $this->createTransaction(42, 'EUR');

        $plugin->checkPaymentInstruction($transaction->getPayment()->getPaymentInstruction());
    }

    /**
     * Test the Check payment instruction with valid datas
     */
    public function testValidCheckPaymentInstruction()
    {
        $plugin = $this->createPluginMock(true, false, 'not_managed');
        $transaction = $this->createTransaction(42, 'EUR');

        $transaction->getExtendedData()->set('lang', 'en_US');

        try {
            $plugin->checkPaymentInstruction($transaction->getPayment()->getPaymentInstruction());
        } catch (\Exception $ex) {
            $this->fail("Exception should not be throw here.");
        }
    }

    /**
     * Test the processes function
     */
    public function testProcesses()
    {
        $plugin = $this->createPluginMock(true, false, 'not_managed');

        $this->assertTrue($plugin->processes('ogone_gateway'));
        $this->assertFalse($plugin->processes('paypal_express_checkout'));
    }

    /**
     * @param $amount
     * @param $currency
     * @param $data
     *
     * @return \JMS\Payment\CoreBundle\Entity\FinancialTransaction
     */
    protected function createTransaction($amount, $currency)
    {
        $transaction = new FinancialTransaction();
        $transaction->setRequestedAmount($amount);

        $extendedData = new ExtendedData();
        $extendedData->set('CN', 'Foo Bar');

        $paymentInstruction = new PaymentInstruction($amount, $currency, 'ogone_gateway', $extendedData);

        $payment = new Payment($paymentInstruction, $amount);
        $payment->addTransaction($transaction);

        return $transaction;
    }

    /**
     * @return OgoneGatewayPlugin
     */
    protected function createPluginMock($debug = false, $utf8 = false, $state = '')
    {
        $tokenMock = $this->getMock('ETS\Payment\OgoneBundle\Client\TokenInterface');
        $filename = sprintf(__DIR__ . '/../../Resources/fixtures/%s.xml', $state);

        return new OgoneGatewayPluginMock($tokenMock, new ShaIn($tokenMock), new Redirection(), new Design(), $debug, $utf8, $filename);
    }
}