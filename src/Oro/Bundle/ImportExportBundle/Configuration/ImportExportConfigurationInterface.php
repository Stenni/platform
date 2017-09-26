<?php

namespace Oro\Bundle\ImportExportBundle\Configuration;

interface ImportExportConfigurationInterface
{
    /**
     * @return array
     */
    public function getRouteOptions(): array;

    /**
     * @return string
     */
    public function getEntityClass(): string;

    /**
     * @return string|null
     */
    public function getFilePrefix();

    /**
     * @return string|null
     */
    public function getExportJobName();

    /**
     * @return string|null
     */
    public function getExportProcessorAlias();

    /**
     * @return string|null
     */
    public function getExportButtonLabel();

    /**
     * @return string|null
     */
    public function getExportPopupTitle();

    /**
     * @return string|null
     */
    public function getExportTemplateJobName();

    /**
     * @return string|null
     */
    public function getExportTemplateProcessorAlias();

    /**
     * @return string|null
     */
    public function getExportTemplateButtonLabel();

    /**
     * @return string|null
     */
    public function getImportJobName();

    /**
     * @return string|null
     */
    public function getImportProcessorAlias();

    /**
     * @return string|null
     */
    public function getImportValidationJobName();

    /**
     * @return string|null
     */
    public function getImportValidationButtonLabel();
}
