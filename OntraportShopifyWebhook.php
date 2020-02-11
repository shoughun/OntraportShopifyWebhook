<?php

/**  
    Title: Shopify -> ONTRAPORT API (SDK) Webhook
    PLEASE NOTE: ONTRAPORT does not support or assist with implementing this.
*/

/*
    To use the Shopify -> ONTRAPORT API webhook
        
        - Install the ONTRAPORT SDK: https://github.com/Ontraport/SDK-PHP
        - Correct autoload.php source below if applicable
        - Host this PHP script
        - Create webhook / obtain key: https://help.shopify.com/en/api/getting-started/webhooks
        - Replace webook key below, as well as your ONTRAPORT API keys
        - Purchase data should be sent to this webhook and logged in ontraport (/ tied to partner IF you follow instructions below)
        - If you want variant product titles, i.e., for "White Tee - XL" and "White Tee - L" to be two different products instead of just "White Tee", be sure to change $useVariantProductTitles to 'true'
        - If you want to send an invoice, change $op_invoiceId to reflect id of ONTRAPORT invoice... default is '-1' which means do not send invoice (assuming Shopify takes care of this already)
*/

/*
    If you also want Partner data...
    
    - Use the Partner Tracking Pixel to add referral information to ONTRAPORT via the purchase TY page... Shopify will send webhook after that
    - Pixel: <img src="https://Y0URsubdomain.ontraport.com/p?aff_only=1&order_id=0&email={{ customer.email }}" />
    - Must use a tracking script... I'd recommend on the initial page and any pages you want to track—but the TY page is required.
    - When using the funnel, must use a Standard Link Promo Tool
    
        More info on Promo Tools: https://support.ontraport.com/hc/en-us/articles/217881518-Add-Partner-Promotional-Tools
        More info on Tracking Script: https://support.ontraport.com/hc/en-us/articles/217882408-Web-Page-Tracking
        More info on Partner Pixel: https://support.ontraport.com/hc/en-us/articles/217881588-Partner-Tracking-Pixel
        More info on where to place Partner Pixel in Shopify: https://help.shopify.com/en/manual/orders/status-tracking/add-conversion-tracking-to-thank-you-page
        
    Example of populated Pixel on TY page: <img src="https://Y0URsubdomain.ontraport.com/p?aff_only=1&order_id=0&email=test@tedsfgdfgst.com" />

           * aff_only=1 means we are JUST sending the contact and partner... since the API will send purchase data
           * order=0, this is required along with aff_only=1... so ONTRAPORT knows no purchase data is expected
           * email=test@test.com, populated on TY page with Shopify's merge field: {{ customer.email }}
*/

//uncomment below to display errors
//ini_set('display_errors', 1); ini_set('display_startup_errors', 1);

// ontraport sdk & keys
require_once('src/vendor/autoload.php');
use OntraportAPI\Ontraport as Client;
use OntraportAPI\ObjectType;

define(ONTRAPORT_APP_ID, "2_142071_RQgtMVonx");
define(ONTRAPORT_API_KEY, "n45ftv9JE0T5oKt");
define(SHOPIFY_WEBHOOK_KEY, "6bf5e17dd06f9b10375a4b45f23a5b20dd02ac292fb3832547062d34647993fb");

// optional
$useVariantProductTitles = false;
$op_invoiceId = -1;

// verify shopify  webhook authenticity
function verify_webhook($data, $hmac_header)
{
        $calculated_hmac = base64_encode(hash_hmac('sha256', $data, SHOPIFY_WEBHOOK_KEY, true));
        return ($hmac_header == $calculated_hmac);
}
$hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
//$data = file_get_contents('test.json');
$data = file_get_contents('php://input');
$verified = verify_webhook($data, $hmac_header);
error_log('Webhook verified: '.var_export($verified, true)); //check error.log to see the result
$data = json_decode($data);

// contact fields
$shop_customerId = $data->customer->id;
$shop_firstName = $data->customer->first_name;
$shop_lastName = $data->customer->last_name;
$shop_customerEmail = $data->customer->email;
$shop_allowMarketing = ($data->customer->buyer_accepts_marketing = true ? 1 : 0); // bulk email status

// billing address
$op_finishedBillingAddress = array (
  "address1" => $data->billing_address->address1,
  "address2" => $data->billing_address->address2,
  "city" => $data->billing_address->city,
  "zip" => $data->billing_address->zip,
  "country" => $data->billing_address->country_code,
  "state" => $data->billing_address->province_code
);

// shipping address
$shop_shippingAddress = $data->shipping_address->address1;
$shop_shippingAddress2 = $data->shipping_address->address2;
$shop_shippingCity = $data->shipping_address->city;
$shop_shippingZip = $data->shipping_address->zip;
$shop_shippingCountry = $data->shipping_address->country_code;
$shop_shippingState = $data->shipping_address->province_code;

// invoice
$shop_orderId = $data->id;
$shop_orderDate = date("U",strtotime($data->created_at))*1000;
$shop_shippingLines = $data->shipping_lines;
$shop_taxLines = $data->tax_lines;
$shop_lineItems = $data->line_items;
$shop_subTotal = $data->subtotal_price;
$shop_grandTotal = $data->total_price;

// client browser details
/*$shop_browserIp = $data->client_details->browser_ip;
$shop_userAgent = $data->client_details->user_agent;
$shop_sessionHash = $data->client_details->session_hash;
$shop_browserWidth = $data->client_details->browser_width;
$shop_browserHeight = $data->client_details->browser_height;*/

// ready products for op
$op_products = array();
foreach ( $shop_lineItems as $shop_lineItem )
{

  if ( $shop_lineItem->taxable == true )
  {
    $offerHasTaxes = true;
  }
  if ( $shop_lineItem->requires_shipping == true )
  {
    $offerHasShipping = true;
  }
 
  $name = $shop_lineItem->title; // mug
  $external_id = $shop_lineItem->id; // 1234
  if ( $useVariantProductTitles == true )
  {
    // make it "mug - 11oz"
    $name .= " - ";
    $name .= strtolower($shop_lineItem->variant_title);
    // make it "1234_5678"
    $external_id .= "_";
    $external_id .= $shop_lineItem->variant_id; // 5678
  }

  $op_products[] = array(
    "id" => "", // obtain below
    "name" => $name,
    "price" => $shop_lineItem->price,
    "external_id" => $external_id,
    "quantity" => (string) $shop_lineItem->quantity,
    "shipped" => $shop_lineItem->requires_shipping,
    "taxable" => $shop_lineItem->taxable
  );
}

// ready taxes for op
$op_taxes = array();
foreach ( $shop_taxLines as $shop_taxLine )
{
  $op_taxes[] = array(
    "id" => "", // obtain below
    "name" => $shop_taxLine->title,
    "price" => $shop_taxLine->price,
    "rate" => ($shop_taxLine->rate)*100
  );
}

// ready shipping methods for op
$op_shippingMethods = array();
foreach ( $shop_shippingLines as $shop_shippingLine )
{
  $op_shippingMethods[] = array(
    "id" => "", // obtain below
    "name" => $shop_shippingLine->code, // use "code" — seems more generic
    "price" => $shop_shippingLine->discounted_price // use "discounted price" —
                                                    // should be the same unless actually discounted
  );
}

// do ontraport api stuff if authorized
if ( $verified == true /*|| $verified == false*/ )
{

    // give time for partner pixel to do it's thing just in case
    sleep(30);

    $client = new Client( ONTRAPORT_APP_ID, ONTRAPORT_API_KEY );

    $condition = new OntraportAPI\Criteria("email", "=", $shop_customerEmail);
    $contactSearch = array(
        "objectID"   => ObjectType::CONTACT, // Object type ID: 16
        "listFields" => "id,email",
        "condition" => $condition->fromArray()
    );
    $contacts = $client->object()->retrieveMultiple( $contactSearch );
    $contacts = json_decode($contacts, true);
    $contactId = $contacts["data"][0]["id"];

    $contactInfo = array(
        "objectID"   => ObjectType::CONTACT, // Object type ID: 0
        "firstname" => $shop_firstName,
        "lastname"  => $shop_lastName,
        "email"     => $shop_customerEmail,
        "address" => $shop_shippingAddress,
        "address2" => $shop_shippingAddress2,
        "city" => $shop_shippingCity,
        "state" => $shop_shippingState,
        "zip" => $shop_shippingZip,
        "country" => $shop_shippingCountry
    );

    if ( $contactId == NULL )
    {
      // create contact
      $contact = $client->object()->create( $contactInfo );
      $contact = json_decode($contact, true);
      $op_contactId = $contact["data"]["id"];
    }
    else
    {
      // update contact
      $contactInfo["id"] = $contactId;
      $contact = $client->object()->update( $contactInfo );
      $contact = json_decode($contact, true);
      $op_contactId = $contact["data"]["attrs"]["id"];
    }

    // see if products already exist in ontraport, and create them if not
    $op_finishedProducts = array();
    foreach ( $op_products as $op_product )
    {
      // search for product on external id
      $condition = new OntraportAPI\Criteria("external_id", "=", $op_product['external_id']);
      $productSearch = array(
          "objectID"   => ObjectType::PRODUCT, // Object type ID: 16
          "listFields" => "id,name,external_id",
          "condition" => $condition->fromArray()
      );
      $products = $client->object()->retrieveMultiple( $productSearch );
      $products = json_decode($products, true);
      $productId = $products["data"][0]["id"];

      if ( $productId == NULL )
      {
        $newProduct = array(
            "objectID"  => ObjectType::PRODUCT, // Object type ID: 16
            "name" => $op_product["name"],
            "external_id"  => $op_product["external_id"],
            "price" => $op_product["price"]
        );
        $product = $client->object()->create( $newProduct );
        $product = json_decode($product, true);
        $op_product["id"] = $product["data"]["id"];
      }
      else // if product already exists
      {
        $op_product["id"] = "$productId";
      }

      $op_finishedProducts[] = array(
                "quantity" => $op_product["quantity"],
                "shipping" => $op_product["shipped"],
                "tax" => $op_product["taxable"],
                "price" => array(
                    array(
                        "price" => $op_product["price"]
                    )
                ),
                "type" => "single",
                "owner" => 1,
                "id" => $op_product["id"]
            );
    }

    // see if taxes already exist in ontraport, and create them if not
    $op_finishedTaxes = array();
    foreach ( $op_taxes as $op_tax )
    {
      // search for tax by name AND rate
      $condition = new OntraportAPI\Criteria( "name", "=", $op_tax["name"] );
      $condition->andCondition( "rate", "=", (string) $op_tax["rate"] );

      $taxSearch = array(
          "objectID"   => ObjectType::TAX, // Object type ID: 63
          "listFields" => "id,name,rate",
          "condition" => $condition->fromArray()
      );
      $taxes = $client->object()->retrieveMultiple( $taxSearch ); // empty
      $taxes = json_decode($taxes, true);
      $taxId = $taxes["data"][0]["id"];

      if ( $taxId == NULL )
      {
        $newTax = array(
            "objectID"  => ObjectType::TAX, // Object type ID: 63
            "name" => $op_tax["name"],
            "rate"  => $op_tax["rate"]
        );
        $tax = $client->object()->create( $newTax );
        //$response = json_decode($response);
        $tax = json_decode($tax, true);
        $op_tax["id"] = $tax["data"]["id"];
      }
      else // if tax already exists
      {
        $op_tax["id"] = $taxId;
      }

      $op_finishedTaxes[] = array(
                "name" => $op_tax["name"],
                "rate" => $op_tax["rate"],
                "id" => $op_tax["id"],
                "taxTotal" => $op_tax["price"],
                "taxShipping" => false
      );
    }

    // see if shipping method already exist in ontraport, and create if not
    $op_finishedShippingMethods = array();
    foreach ( $op_shippingMethods as $op_shippingMethod )
    {
      // search for shipping by name AND rate
      $condition = new OntraportAPI\Criteria( "name", "=", $op_shippingMethod["name"] );
      $condition->andCondition( "price", "=", $op_shippingMethod["price"] );

      $shippingSearch = array(
          "objectID"   => ObjectType::SHIPPING_METHOD, // Object type ID: 64
          "listFields" => "id,name,price",
          "condition" => $condition->fromArray()
      );
      $shippingMethods = $client->object()->retrieveMultiple( $shippingSearch );
      $shippingMethods = json_decode($shippingMethods, true);
      $shippingMethodId = $shippingMethods["data"][0]["id"];

      if ( $shippingMethodId == NULL )
      {
        $newShipping = array(
            "objectID"  => ObjectType::SHIPPING_METHOD, // Object type ID: 64
            "name" => $op_shippingMethod["name"],
            "price"  => $op_shippingMethod["price"]
        );
        $shippingMethod = $client->object()->create( $newShipping );
        $shippingMethod = json_decode($shippingMethod, true);
        $op_shippingMethod["id"] = $shippingMethod["data"]["id"];

      }
      else // if shipping already exists
      {
        $op_shippingMethod["id"] = $shippingMethodId;
      }

      $op_finishedShippingMethods[] = array(
                "id" => $op_shippingMethod["id"],
                "name" => $op_shippingMethod["name"],
                "price" => $op_shippingMethod["price"]
            );
    }

    // build request data
    $logTransaction = array(
      "contact_id"       => $op_contactId,
      "invoice_template" => $op_invoiceId,
      "trans_date" => "$shop_orderDate",
      "chargeNow"        => "chargeLog",
      "offer"            => array(
          "products"        => $op_finishedProducts,
          "taxes" => $op_finishedTaxes,
          "shipping" => $op_finishedShippingMethods,
          "subTotal" => $shop_subTotal,
          "grandTotal" => $shop_grandTotal,
          "hasTaxes" => $offerHasTaxes,
          "hasShipping" => $offerHasShipping
      ),
      "external_order_id" => $shop_orderId,
      "billing_address"    => $op_finishedBillingAddress
    );
    $response = $client->transaction()->processManual( $logTransaction );
    $response = json_decode($response, true);

    echo "<pre>";
    var_dump($response);
    echo "</pre>";
}
