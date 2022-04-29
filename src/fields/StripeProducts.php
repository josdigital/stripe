<?php
/**
 * Stripe Payments plugin for Craft CMS 3.x
 *
 * @link      https://enupal.com/
 * @copyright Copyright (c) 2018 Enupal LLC
 */

namespace enupal\stripe\fields;

use craft\fields\BaseRelationField;
use enupal\stripe\elements\Product;
use enupal\stripe\Stripe as StripePlugin;

/**
 * Class StripeProducts
 *
 */
class StripeProducts extends BaseRelationField
{
    /**
     * @inheritdoc
     */
    public $allowMultipleSources = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return StripePlugin::t('Stripe Products');
    }

    /**
     * @inheritdoc
     */
    protected static function elementType(): string
    {
        return Product::class;
    }

    /**
     * @inheritdoc
     */
    public static function defaultSelectionLabel(): string
    {
        return StripePlugin::t('Add a Stripe Product');
    }
}
