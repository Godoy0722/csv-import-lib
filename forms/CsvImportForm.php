<?php

/**
 * @file plugins/importexport/csv/shared/forms/CsvImportForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CsvImportForm
 *
 * @ingroup plugins_importexport_csv
 *
 * @brief Shared base form for CSV import. Each application (OJS/OPS/OMP)
 *        extends this and provides its own import type options.
 */

namespace APP\plugins\importexport\csv\shared\forms;

use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldUpload;
use PKP\components\forms\FormComponent;
use PKP\facades\Locale;

define('FORM_CSV_IMPORT', 'csvImport');

class CsvImportForm extends FormComponent
{
    public $id = FORM_CSV_IMPORT;
    public $method = 'POST';

    /**
     * @param string $action   Form submission URL
     * @param string $uploadUrl  Temporary file upload API URL
     * @param array  $importTypes  Array of ['value' => string, 'label' => string]
     * @param string $defaultType  Default selected import type value
     */
    public function __construct(string $action, string $uploadUrl, array $importTypes, string $defaultType)
    {
        $this->action = $action;
        $primaryLocale = Locale::getPrimaryLocale();
        $this->locales = [['key' => $primaryLocale, 'label' => Locale::getMetadata($primaryLocale)?->getDisplayName() ?? $primaryLocale]];

        $this
            ->addPage(['id' => 'default', 'submitButton' => ['label' => __('plugins.importexport.csv.form.submitButton')]])
            ->addGroup(['id' => 'default', 'pageId' => 'default'])
            ->addField(new FieldUpload('importFile', [
                'label' => __('plugins.importexport.csv.form.importFile'),
                'description' => __('plugins.importexport.csv.form.importFile.description'),
                'isRequired' => true,
                'groupId' => 'default',
                'options' => [
                    'url' => $uploadUrl,
                    'acceptedFiles' => '.zip,.csv',
                ],
            ]))
            ->addField(new FieldSelect('importType', [
                'label' => __('plugins.importexport.csv.form.importType'),
                'isRequired' => true,
                'groupId' => 'default',
                'options' => $importTypes,
                'value' => $defaultType,
            ]))
            ->addField(new FieldOptions('dryMode', [
                'label' => __('plugins.importexport.csv.form.dryMode'),
                'description' => __('plugins.importexport.csv.form.dryMode.description'),
                'type' => 'checkbox',
                'groupId' => 'default',
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.csv.form.dryMode.enable')],
                ],
                'value' => [],
            ]))
            ->addField(new FieldOptions('sendWelcomeEmail', [
                'label' => __('plugins.importexport.csv.form.sendWelcomeEmail'),
                'type' => 'checkbox',
                'groupId' => 'default',
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.csv.form.sendWelcomeEmail.enable')],
                ],
                'value' => [],
                'showWhen' => ['importType', 'users'],
            ]));
    }
}
