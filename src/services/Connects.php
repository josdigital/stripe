<?php
/**
 * Stripe Payments plugin for Craft CMS 3.x
 *
 * @link      https://enupal.com/
 * @copyright Copyright (c) 2018 Enupal LLC
 */

namespace enupal\stripe\services;

use craft\db\Query;
use craft\services\Plugins;
use enupal\stripe\elements\Commission;
use enupal\stripe\elements\Connect;
use enupal\stripe\elements\PaymentForm;
use enupal\stripe\Stripe as StripePlugin;
use Stripe\Exception\OAuth\InvalidGrantException;
use Stripe\OAuth;
use yii\base\Component;
use Craft;

class Connects extends Component
{
    /**
     * Returns a Connect model if one is found in the database by id
     *
     * @param int $id
     *
     * @return null|Connect
     */
    public function getConnectById(int $id)
    {
        $connect = Craft::$app->getElements()->getElementById($id);

        return $connect;
    }

    /**
     * @return array
     */
    public function getConnectProductTypes()
    {
        $productTypes = [
            PaymentForm::class
        ];

        return $productTypes;
    }

    /**
     * @return array
     */
    public function getConnectProductTypesAsOptions()
    {
        $productTypes = $this->getConnectProductTypes();
        $options = [];

        foreach ($productTypes as $productType) {
            $name = $productType::displayName();
            $options[] = [
                'label' => $name,
                'value' => $productType
            ];
        }

        return $options;
    }

    /**
     * @param string $productType
     *
     * @return Connect
     * @throws \Exception
     * @throws \Throwable
     */
    public function createNewConnect(string $productType): Connect
    {
        $settings = StripePlugin::$app->settings->getSettings();
        $connect = new Connect();

        $connect->productType = $productType;
        $connect->enabled = 0;
        $connect->rate = $settings->globalRate;

        Craft::$app->elements->saveElement($connect, false);

        return $connect;
    }

    /**
     * @return bool
     */
    public function isCommerceInstalled()
    {
        $pluginHandle = 'commerce';
        $projectConfig = Craft::$app->getProjectConfig();
        $commerceSettings = $projectConfig->get(Plugins::CONFIG_PLUGINS_KEY.'.'.$pluginHandle);
        $isInstalled = $commerceSettings['enabled'] ?? false;

        return $isInstalled;
    }

    /**
     * @param Connect $connect
     *
     * @return Connect
     */
    public function populateConnectFromPost(Connect $connect)
    {
        $request = Craft::$app->getRequest();

        $postFields = $request->getBodyParam('fields');

        $postFields['vendorId'] = is_array($postFields['vendorId']) ? $postFields['vendorId'][0] : $postFields['vendorId'];

        $connect->setAttributes(/** @scrutinizer ignore-type */
            $postFields, false);

        return $connect;
    }

    /**
     * @param Connect $connect
     *
     * @return bool
     * @throws \Throwable
     */
    public function deleteConnect(Connect $connect)
    {
        $transaction = Craft::$app->db->beginTransaction();

        try {
            // Delete the commissions
            $commissions = (new Query())
                ->select(['id'])
                ->from(["{{%enupalstripe_commissions}}"])
                ->where(['connectId' => $connect->id])
                ->all();

            foreach ($commissions as $commission) {
                Craft::$app->elements->deleteElementById($commission['id'], Commission::class, null, true);
            }

            // Delete the Connect
            $success = Craft::$app->elements->deleteElementById($connect->id, Connect::class,null, true);

            if (!$success) {
                $transaction->rollback();
                Craft::error("Couldn’t delete Connect", __METHOD__);

                return false;
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * @param $params
     * @param PaymentForm $form
     * @return mixed
     */
    public function processSessionParams($params, PaymentForm $form)
    {
        /** @var Connect $connect */
        $connect = StripePlugin::$app->commissions->getConnectByPaymentFormId($form->id);

        if (is_null($connect)) {
            // No connect for this payment form
            return $params;
        }

        $vendor = $connect->getVendor();

        if (is_null($vendor)) {
            Craft::error('Unable to process commission as vendor does not exists');
            return $params;
        }

        if (empty($vendor->stripeId)) {
            Craft::error('Unable to process commission as vendor does not have a Stripe account linked');
            return $params;
        }

        Craft::info('Processing commission for '.$vendor->id);
        $platformFeeRate = 100 - $connect->rate;
        $finalAmount = 0;

        foreach ($params['line_items'] as $line_item) {
            $finalAmount += $line_item['amount'];
        }

        $platformFee = $finalAmount * ($platformFeeRate / 100);

        $params['payment_intent_data']['application_fee_amount'] = $platformFee;
        $params['payment_intent_data']['transfer_data'] = [
            'destination' => $vendor->stripeId
        ];

        return $params;
    }

    /**
     * @param $code
     * @return mixed|null
     * @throws \Exception
     */
    public function getStripeUserIdFromCode($code)
    {
        StripePlugin::$app->settings->initializeStripe();

        try {
            $stripeResponse =  OAuth::token([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);
        } catch (InvalidGrantException $e) {
            Craft::error('Invalid authorization code: ' . $code, __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('An unknown error occurred. '.$e->getMessage(), __METHOD__);
            return null;
        }

        return $stripeResponse->stripe_user_id;
    }
}
