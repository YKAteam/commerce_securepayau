<?php

namespace Drupal\commerce_securepayau\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Class SecurePayXML.
 */
class SecurePayXML extends ControllerBase {
  /**
   * API Details from Payment method form.
   *
   * @var array
   */
  private $configuration;

  /**
   * Variable for payme.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentInterface
   */
  private $payment;

  /**
   * Payment Details from Payment Form.
   *
   * @var array
   */
  private $paymentDetails;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   Configuration.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   Payment entity.
   * @param array $payment_details
   *   Payment details.
   */
  public function __construct(array $configuration, PaymentInterface $payment, array $payment_details) {
    $this->configuration = $configuration;
    $this->payment = $payment;
    $this->paymentDetails = $payment_details;
  }

  /**
   * Sends an XML API Request to securepay.com.au.
   *
   * @return array
   *   XML Response. E.g.
   *   ```php
   *   (
   *    [messageID] => ed8d55136b6651c5fc
   *    [messageTimestamp] => 20190403171157187000+660
   *    [apiVersion] => spxml-3.0
   *    [RequestType] => Payment
   *    [merchantID] => ABC0001
   *    [statusCode] => 000
   *    [statusDescription] => Normal
   *    [txnType] => 0
   *    [txnSource] => 23
   *    [amount] => 150000
   *    [currency] => AUD
   *    [purchaseOrderNo] => 9
   *    [approved] => Yes
   *    [responseCode] => 00
   *    [responseText] => Approved
   *    [thinlinkResponseCode] => 100
   *    [thinlinkResponseText] => 000
   *    [thinlinkEventStatusCode] => 000
   *    [thinlinkEventStatusText] => Normal
   *    [settlementDate] => 20190304
   *    [txnID] => 223941
   *    [pan] => 444433...111
   *    [expiryDate] => 08/23
   *    [cardType] => 6
   *    [cardDescription] => Visa
   *   )
   *   ```
   */
  public function sendXmlRequest() {
    $xml = $this->createXmlRequestString();

    if ($this->configuration['mode'] == 'live') {
      $post_url = $this->configuration['gateway_urls']['live'];
    }
    else {
      $post_url = $this->configuration['gateway_urls']['test'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $post_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_POST, 1);
    $response = curl_exec($ch);

    // Log any errors to the watchdog.
    if ($error = curl_error($ch)) {
      \Drupal::logger('commerce_securepayau')->error('cURL error: @error', ['@error' => $error]);
      throw new Exception($error);
    }
    curl_close($ch);

    return $this->parseXmlResponse($response);
  }

  /**
   * Verifies the payment status.
   *
   * @param array $response
   *   Payment response, including keys 'statusCode' and 'approved'.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the status code is not '000' (successful).
   * @throws \Drupal\commerce_payment\Exception\HardDeclineException
   *   When the payment was declined.
   *
   * @return bool
   *   TRUE when the payment was verified.
   */
  public function verifyPaymentStatus(array $response) {
    if ($response['statusCode'] !== '000') {
      throw new PaymentGatewayException($response['statusCode']);
    }

    if ($response['approved'] === 'No') {
      throw new HardDeclineException('The payment was declined');
    }

    return TRUE;
  }

  /**
   * Create a XML request string.
   *
   * Wraps XML API request child elements in the request
   * element and includes the merchant authentication information.
   *
   * @return string
   *   The XML request string.
   */
  public function createXmlRequestString() {
    $payment_details = $this->paymentDetails;
    if (!$payment_details) {
      throw new Exception("Please enter payment details to continue.");
    }

    $message_id = $this->getUniqueMessageId(15, 25, 'abcdef0123456789');
    $timeout = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);

    $timestamp = date('YmdHis000+600');
    $api_version = 'spxml-3.0';
    $payment_request_type = "Payment";
    $xml_merchant_info = [
      'MessageInfo' => [
        'messageID' => $message_id,
        'messageTimestamp' => $timestamp,
        'timeoutValue' => $timeout,
        'apiVersion' => $api_version,
      ],
      'MerchantInfo' => [
        'merchantID' => $this->configuration['merchant_id'],
        'password' => $this->configuration['password'],
      ],
      'RequestType' => $payment_request_type,
    ];

    $order = $this->payment->getOrder();
    $price = round($order->getTotalPrice()->getNumber() * 100);
    $cc = $payment_details['number'];
    $exp = $payment_details['expiration']['month'] . '/' .
               substr($payment_details['expiration']['year'], -2);
    $ccv = $payment_details['security_code'];
    $xml_payment_info = [
      'Payment' => [
        'TxnList count="1"' => [
          'Txn ID="1"' => [
            'txnType' => '0',
            'txnSource' => '23',
            'amount' => $price,
            'currency' => $order->getTotalPrice()->getCurrencyCode(),
            'purchaseOrderNo' => $order->id(),
            'CreditCardInfo' => [
              'cardNumber' => $cc,
              'cvv' => $ccv,
              'expiryDate' => $exp,
            ],
          ],
        ],
      ],
    ];

    $xml_request = "<?xml version='1.0' encoding='UTF-8'?>\n<SecurePayMessage>\n" . $this->arrayToXml($xml_merchant_info) .
            $this->arrayToXml($xml_payment_info) .
            '</SecurePayMessage>';

    return $xml_request;

  }

  /**
   * Parse the securepay.com.au XML API response.
   *
   * @param string $content
   *   Response content.
   *
   * @return array
   *   Response array.
   */
  private static function parseXmlResponse(string &$content) {
    $response = [];
    preg_match_all('/<([^\/><]*?)>([^\<].*?)<\/.*?>/mi', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $response[$match[1]] = $match[2];
    }

    return $response;
  }

  /**
   * Generates a random text string (used for creating a unique message ID).
   *
   * @return string
   *   A random text string.
   */
  public function getUniqueMessageId(
        $min = 10,
        $max = 20,
        $randtext = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890'
    ) {
    $min = $min < 1 ? 1 : $min;
    $varlen = rand($min, $max);
    $randtextlen = strlen($randtext);
    $text = '';

    for ($i = 0; $i < $varlen; $i++) {
      $text .= substr($randtext, rand(1, $randtextlen), 1);
    }
    return $text;
  }

  /**
   * Converts a hierarchical array of elements into an XML string.
   *
   * @param array $data
   *   Array structure to convert into xml string.
   * @param int $depth
   *   The depth of the elements.
   *
   * @return string
   *   XML string.
   */
  private  function arrayToXml(array $data, $depth = 0) {
    $xml = '';

    $padding = '  ';
    for ($i = 0; $i < $depth; $i++) {
      $padding .= '  ';
    }

    // Loop through the elements in the data array.
    foreach ($data as $element => $contents) {
      if (is_array($contents)) {
        // Render the element with its child elements.
        $xml .= "{$padding}<{$element}>\n" . $this->arrayToXml($contents, $depth + 1) . "{$padding}</" . strtok($element, ' ') . ">\n";
      }
      else {
        // Render the element with its contents.
        $xml .= "{$padding}<{$element}>{$contents}</{$element}>\n";
      }
    }

    return $xml;
  }

}
