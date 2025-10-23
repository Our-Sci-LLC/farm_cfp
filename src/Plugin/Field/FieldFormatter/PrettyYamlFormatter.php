<?php

namespace Drupal\farm_cfp\Plugin\Field\FieldFormatter;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'pretty_yaml' formatter.
 */
#[FieldFormatter(
  id: 'pretty_yaml',
  label: new TranslatableMarkup('Pretty YAML'),
  field_types: [
    'string_long',
    'text_long',
    'text_with_summary',
  ],
)]
class PrettyYamlFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a PrettyYamlFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $value = $item->value;

      if (!empty($value)) {
        try {
          // Decode and re-encode the YAML to ensure it's properly formatted.
          $decoded = Yaml::decode($value);
          $formatted_yaml = Yaml::encode($decoded);
        }
        catch (InvalidDataTypeException $e) {
          $this->loggerFactory->get('farm_cfp')->error('Invalid YAML in field: @error', ['@error' => $e->getMessage()]);
          $formatted_yaml = $this->t('Invalid YAML format.');
        }
      } else {
        $formatted_yaml = '';
      }

      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $formatted_yaml,
        '#attributes' => [
          'class' => ['pretty-yaml'],
          'style' => 'background: var(--gin-bg-item); padding: 10px; border: 1px solid var(--gin-bg-item-hover); border-radius: 3px; font-size: var(--gin-font-size-xs)',
        ],
      ];
    }

    return $elements;
  }
}
