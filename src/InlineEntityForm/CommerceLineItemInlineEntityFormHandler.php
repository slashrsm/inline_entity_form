<?php

/**
 * @file
 * Defines the inline entity form controller for Commerce Line Items.
 */

namespace Drupal\inline_entity_form\InlineEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Commerce line item inline entity form handler.
 *
 * @TODO - this needs to be ported. Not in a working state ATM.
 */
class CommerceLineItemInlineEntityFormHandler extends EntityInlineEntityFormHandler {

  /**
   * Overrides EntityInlineEntityFormHandler::tableFields().
   */
  public function tableFields($bundles) {
    $fields = array();
    $fields['line_item_label'] = array(
      'type' => 'property',
      'label' => t('Label'),
      'weight' => 1,
    );
    $fields['commerce_unit_price'] = array(
      'type' => 'field',
      'label' => t('Unit price'),
      'formatter' => 'commerce_price_formatted_amount',
      'weight' => 2,
    );
    $fields['quantity'] = array(
      'type' => 'property',
      'label' => t('Quantity'),
      'weight' => 3,
    );
    $fields['commerce_total'] = array(
      'type' => 'field',
      'label' => t('Total'),
      'formatter' => 'commerce_price_formatted_amount',
      'weight' => 4,
    );

    return $fields;
  }

  /**
   * Overrides EntityInlineEntityFormHandler::defaultSettings().
   */
  public function defaultSettings() {
    $defaults = parent::defaultSettings();

    // Line items should always be deleted when the order is deleted, they
    // are never managed alone.
    $defaults['delete_references'] = TRUE;

    return $defaults;
  }

  /**
   * Overrides EntityInlineEntityFormHandler::settingsForm().
   */
  public function settingsForm($field, $instance) {
    $form = parent::settingsForm($field, $instance);

    // Adding existing entities is not supported for line items.
    $form['allow_existing']['#access'] = FALSE;
    $form['match_operator']['#access'] = FALSE;

    return $form;
  }

  /**
   * Overrides EntityInlineEntityFormHandler::entityForm().
   */
  public function entityForm($entity_form, &$form_state) {
    $line_item = $entity_form['#entity'];
    $extra_fields = field_info_extra_fields('commerce_line_item', $line_item->type, 'form');

    $entity_form['line_item_details'] = array(
      '#type' => 'fieldset',
      '#title' => t('Line item details'),
      '#attributes' => array('class' => array('ief-line_item-details', 'ief-entity-fieldset')),
    );
    $entity_form['line_item_label'] = array(
      '#type' => 'textfield',
      '#title' => t('Line item label'),
      '#description' => t('Supply the line item label to be used for this line item.'),
      '#default_value' => $line_item->line_item_label,
      '#maxlength' => 128,
      '#required' => TRUE,
      '#weight' => $extra_fields['label']['weight'],
      '#fieldset' => 'line_item_details',
    );
    $entity_form['quantity'] = array(
      '#type' => 'textfield',
      '#datatype' => 'integer',
      '#title' => t('Quantity'),
      '#description' => t('The quantity of line items.'),
      '#default_value' => (int) $line_item->quantity,
      '#size' => 4,
      '#maxlength' => max(4, strlen($line_item->quantity)),
      '#required' => TRUE,
      '#weight' => $extra_fields['label']['weight'],
      '#fieldset' => 'line_item_details',
    );
    field_attach_form($line_item, $entity_form, $form_state);

    // If the order is still in a cart status, then the prices are still being
    // recalculated, meaning that no changes made to them would be permanent.
    if (!empty($form_state['commerce_order']) && module_exists('commerce_order')) {
      if (commerce_cart_order_is_cart($form_state['commerce_order'])) {
        $language = $entity_form['commerce_unit_price']['#language'];
        $entity_form['commerce_unit_price'][$language][0]['amount']['#disabled'] = TRUE;
        $entity_form['commerce_unit_price'][$language][0]['currency_code']['#disabled'] = TRUE;
      }
    }

    // Tweaks specific to product line items.
    if (in_array($line_item->type, $this->productLineItemTypes())) {
      $entity_form['line_item_label']['#access'] = FALSE;
      $entity_form['commerce_display_path']['#access'] = FALSE;
      $entity_form['commerce_product']['#weight'] = -100;
    }

    // Add all fields to the main fieldset.
    foreach (field_info_instances('commerce_line_item', $line_item->type) as $a => $instance) {
      $entity_form[$instance['field_name']]['#fieldset'] = 'line_item_details';
    }

    return $entity_form;
  }

  /**
   * Overrides EntityInlineEntityFormHandler::entityFormValidate().
   *
   * @todo Remove once Commerce gets a quantity #element_validate function.
   */
  public static function entityFormValidate($entity_form, FormStateInterface $form_state) {
    $line_item = $entity_form['#entity'];

    $parents_path = implode('][', $entity_form['#parents']);
    $line_item_values = NestedArray::getValue($form_state['values'], $entity_form['#parents']);
    $quantity = $line_item_values['quantity'];

    if (!is_numeric($quantity) || $quantity <= 0) {
      form_set_error($parents_path . '][quantity', t('You must specify a positive number for the quantity'));
    }
    elseif ($entity_form['quantity']['#datatype'] == 'integer' &&
      (int) $quantity != $quantity) {
      form_set_error($parents_path . '][quantity', t('You must specify a whole number for the quantity.'));
    }

    field_attach_form_validate($line_item, $entity_form, $form_state);
  }

  /**
   * Overrides EntityInlineEntityFormHandler::entityFormSubmit().
   */
  public function entityFormSubmit(&$entity_form, &$form_state) {
    $line_item = $entity_form['#entity'];
    $line_item_values = NestedArray::getValue($form_state['values'], $entity_form['#parents']);
    $line_item->quantity = sprintf("%.2f", $line_item_values['quantity']);
    field_attach_submit('commerce_line_item', $line_item, $entity_form, $form_state);
    commerce_line_item_rebase_unit_price($line_item);

    $wrapper = entity_metadata_wrapper('commerce_line_item', $line_item);
    // Update the line item label based on the line item type.
    // Product line item types always get the product SKU as the label.
    if (in_array($line_item->type, $this->productLineItemTypes())) {
      $wrapper->line_item_label = $wrapper->commerce_product->sku->value();
    }
    else {
      $wrapper->line_item_label = trim($line_item_values['line_item_label']);
    }

    // Update the total price.
    $unit_price = $wrapper->commerce_unit_price->value();
    $wrapper->commerce_total->amount = $line_item->quantity * $unit_price['amount'];
    $wrapper->commerce_total->currency_code = $unit_price['currency_code'];
    // Add the components multiplied by the quantity to the data array.
    foreach ($unit_price['data']['components'] as $key => &$component) {
      $component['price']['amount'] *= $line_item->quantity;
    }
    // Set the updated data array to the total price.
    $wrapper->commerce_total->data = $unit_price['data'];
  }

  /**
   * Returns an array of product line item types.
   *
   * If the commerce_product_reference module is missing, returns an empty
   * array as a fallback.
   */
  protected function productLineItemTypes() {
    if (module_exists('commerce_product_reference')) {
      return commerce_product_line_item_types();
    }
    else {
      return array();
    }
  }
}
