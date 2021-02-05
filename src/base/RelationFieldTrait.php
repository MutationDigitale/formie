<?php
namespace verbb\formie\base;

use verbb\formie\models\IntegrationField;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\ElementHelper;
use craft\helpers\Template as TemplateHelper;

trait RelationFieldTrait
{
    // Properties
    // =========================================================================

    public $displayType = 'dropdown';


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getIsFieldset(): bool
    {
        if ($this->displayType === 'checkboxes') {
            return true;
        }

        if ($this->displayType === 'radio') {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function serializeValueForExport($value, ElementInterface $element = null)
    {
        $value = $this->_all($value, $element);

        return array_reduce($value->all(), function($acc, $input) {
            return $acc . (string)$input;
        }, '');
    }

    /**
     * @inheritDoc
     */
    public function serializeValueForIntegration($value, ElementInterface $element = null)
    {
        return array_map(function($input) {
            return (string)$input;
        }, $this->_all($value, $element)->all());
    }

    /**
     * @inheritDoc
     */
    public function getPreviewElements(): array
    {
        $options = array_map(function($input) {
            return ['label' => (string)$input, 'value' => $input->id];
        }, $this->getElementsQuery()->limit(5)->all());

        return [
            'total' => $this->getElementsQuery()->count(),
            'options' => $options,
        ];
    }

    /**
     * @inheritDoc
     */
    public function modifyFieldSettings($settings)
    {
        $defaultValue = $this->defaultValue ?? [];

        // For a default value, supply extra content that can't be called directly in Vue, like it can in Twig.
        if ($ids = ArrayHelper::getColumn($defaultValue, 'id')) {
            $elements = static::elementType()::find()->id($ids)->all();

            // Maintain an options array so we can keep track of the label in Vue, not just the saved value
            $settings['defaultValueOptions'] = array_map(function($input) {
                return ['label' => (string)$input, 'value' => $input->id];
            }, $elements);

            // Render the HTML needed for the element select field (for default value). jQuery needs DOM manipulation
            // so while gross, we have to supply the raw HTML, as opposed to models in the Vue-way.
            $settings['defaultValueHtml'] = Craft::$app->getView()->renderTemplate('formie/_includes/element-select-inuput-elements', ['elements' => $elements]);
        }

        // For certain display types, pre-fetch elements for use in the preview in the CP for the field. Saves an initial Ajax request
        if ($this->displayType === 'checkboxes' || $this->displayType === 'radio') {
            $settings['elements'] = $this->getPreviewElements();
        }

        return $settings;
    }

    /**
     * @inheritDoc
     */
    public function getCpElementHtml(array &$context)
    {
        if (!isset($context['element'])) {
            return null;
        }

        /** @var Element $element */
        $element = $context['element'];
        $label = $element->getUiLabel();

        if (!isset($context['context'])) {
            $context['context'] = 'index';
        }

        // How big is the element going to be?
        if (isset($context['size']) && ($context['size'] === 'small' || $context['size'] === 'large')) {
            $elementSize = $context['size'];
        } else if (isset($context['viewMode']) && $context['viewMode'] === 'thumbs') {
            $elementSize = 'large';
        } else {
            $elementSize = 'small';
        }

        // Create the thumb/icon image, if there is one
        // ---------------------------------------------------------------------

        $thumbSize = $elementSize === 'small' ? 34 : 120;
        $thumbUrl = $element->getThumbUrl($thumbSize);

        if ($thumbUrl !== null) {
            $imageSize2x = $thumbSize * 2;
            $thumbUrl2x = $element->getThumbUrl($imageSize2x);

            $srcsets = [
                "$thumbUrl {$thumbSize}w",
                "$thumbUrl2x {$imageSize2x}w",
            ];
            $sizesHtml = "{$thumbSize}px";
            $srcsetHtml = implode(', ', $srcsets);
            $imgHtml = "<div class='elementthumb' data-sizes='{$sizesHtml}' data-srcset='{$srcsetHtml}'></div>";
        } else {
            $imgHtml = '';
        }

        $htmlAttributes = array_merge(
            $element->getHtmlAttributes($context['context']),
            [
                'class' => 'element ' . $elementSize,
                'data-type' => get_class($element),
                'data-id' => $element->id,
                'data-site-id' => $element->siteId,
                'data-status' => $element->getStatus(),
                'data-label' => (string)$element,
                'data-url' => $element->getUrl(),
                'data-level' => $element->level,
                'title' => $label . (Craft::$app->getIsMultiSite() ? ' – ' . $element->getSite()->name : ''),
            ]);

        if ($context['context'] === 'field') {
            $htmlAttributes['class'] .= ' removable';
        }

        if ($element->hasErrors()) {
            $htmlAttributes['class'] .= ' error';
        }

        if ($element::hasStatuses()) {
            $htmlAttributes['class'] .= ' hasstatus';
        }

        if ($thumbUrl !== null) {
            $htmlAttributes['class'] .= ' hasthumb';
        }

        $html = '<div';

        // todo: swap this with Html::renderTagAttributse in 4.0
        // (that will cause a couple breaking changes since `null` means "don't show" and `true` means "no value".)
        foreach ($htmlAttributes as $attribute => $value) {
            $html .= ' ' . $attribute . ($value !== null ? '="' . Html::encode($value) . '"' : '');
        }

        if (ElementHelper::isElementEditable($element)) {
            $html .= ' data-editable';
        }

        if ($element->trashed) {
            $html .= ' data-trashed';
        }

        $html .= '>';

        if ($context['context'] === 'field' && isset($context['name'])) {
            $html .= '<input type="hidden" name="' . $context['name'] . '[]" value="' . $element->id . '">';
            $html .= '<a class="delete icon" title="' . Craft::t('app', 'Remove') . '"></a> ';
        }

        if ($element::hasStatuses()) {
            $status = $element->getStatus();
            $statusClasses = $status . ' ' . ($element::statuses()[$status]['color'] ?? '');
            $html .= '<span class="status ' . $statusClasses . '"></span>';
        }

        $html .= $imgHtml;
        $html .= '<div class="label">';

        $html .= '<span class="title">';

        // CHANGED - allow linking off the label
        $encodedLabel = Html::encode($label);
        $cpEditUrl = Html::encode($element->getCpEditUrl());
        $html .= "<a href=\"{$cpEditUrl}\" target=\"_blank\">{$encodedLabel}</a>";

        $html .= '</span></div></div>';

        return TemplateHelper::raw($html);
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        /** @var Element|null $element */
        if ($element !== null && $element->hasEagerLoadedElements($this->handle)) {
            $value = $element->getEagerLoadedElements($this->handle);
        } else {
            /** @var ElementQueryInterface $value */
            $value = $this->_all($value, $element);
        }

        /** @var ElementQuery|array $value */
        $variables = $this->inputTemplateVariables($value, $element);

        $variables['field'] = $this;

        return Craft::$app->getView()->renderTemplate($this->inputTemplate, $variables);
    }

    /**
     * @inheritDoc
     */
    public function getFieldMappedValueForIntegration(IntegrationField $integrationField, $formField, $value, $submission)
    {
        // Override the value to get full elements
        $value = $submission->getFieldValue($formField->handle);

        // Send through a CSV of element titles, when mapping to a string
        if ($integrationField->getType() === IntegrationField::TYPE_STRING) {
            $titles = ArrayHelper::getColumn($value->all(), 'title');

            return implode(', ', $titles);
        }

        if ($integrationField->getType() === IntegrationField::TYPE_ARRAY) {
            return $value->ids();
        }

        return null;
    }

    public function getDefaultValueQuery()
    {
        $defaultValue = $this->defaultValue ?? [];

        if ($ids = ArrayHelper::getColumn($defaultValue, 'id')) {
            return static::elementType()::find()->id($ids);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getFieldOptions()
    {
        $options = [];

        if ($this->displayType === 'dropdown') {
            $options[] = ['label' => $this->placeholder, 'value' => ''];
        }

        foreach ($this->getElementsQuery()->all() as $element) {
            $options[] = ['label' => (string)$element, 'value' => $element->id];
        }

        return $options;
    }

    /**
     * @inheritDoc
     */
    public function getDisplayTypeValue($value)
    {
        if ($this->displayType === 'checkboxes') {
            $options = [];

            if ($value) {
                foreach ($value->all() as $element) {
                    $options[] = new OptionData((string)$element, $element->id, true);
                }
            }

            return new MultiOptionsFieldData($options);
        }

        if ($this->displayType === 'radio') {
            if ($value) {
                if ($element = $value->one()) {
                    return new SingleOptionFieldData((string)$element, $element->id, true);
                }
            }

            return null;
        }

        if ($this->displayType === 'dropdown') {
            if ($value) {
                if ($element = $value->one()) {
                    return new SingleOptionFieldData((string)$element, $element->id, true);
                }
            }

            return null;
        }
    }


    // Private Methods
    // =========================================================================

    /**
     * Returns a clone of the element query value, prepped to include disabled and cross-site elements.
     *
     * @param ElementQueryInterface $query
     * @param ElementInterface|null $element
     * @return ElementQueryInterface
     */
    private function _all(ElementQueryInterface $query, ElementInterface $element = null): ElementQueryInterface
    {
        $clone = clone $query;
        $clone
            ->anyStatus()
            ->siteId('*')
            ->unique();

        if ($element !== null) {
            $clone->preferSites([$this->targetSiteId($element)]);
        }
        return $clone;
    }
}
