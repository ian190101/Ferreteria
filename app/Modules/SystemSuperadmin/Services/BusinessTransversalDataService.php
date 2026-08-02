<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\Product;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CommissionRule;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicDocumentTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\ImportProfileTemplate;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;

class BusinessTransversalDataService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'summary' => [
                'entities' => DynamicEntity::query()->count(),
                'relationships' => DynamicRelationshipDefinition::query()->count(),
                'attachments' => AttachmentDefinition::query()->count(),
                'forms' => DynamicFormDefinition::query()->count(),
                'form_fields' => DynamicFormFieldRule::query()->count(),
                'document_templates' => DynamicDocumentTemplate::query()->count(),
                'report_templates' => DynamicReportTemplate::query()->count(),
                'calculation_formulas' => CalculationFormula::query()->count(),
                'custom_fields' => CustomFieldDefinition::query()->count(),
                'workflows' => WorkflowDefinition::query()->count(),
                'states' => BusinessStateDefinition::query()->count(),
                'workflow_transitions' => WorkflowTransition::query()->count(),
                'resources' => ReservableResource::query()->count(),
                'price_lists' => PriceList::query()->count(),
                'commissions' => CommissionRule::query()->count(),
                'notifications' => NotificationRule::query()->count(),
                'currencies' => BusinessCurrency::query()->count(),
                'printers' => PrinterProfile::query()->count(),
                'licenses' => BusinessModuleLicense::query()->count(),
                'imports' => ImportProfileTemplate::query()->count(),
            ],
            'entities' => DynamicEntity::query()->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'relationships' => DynamicRelationshipDefinition::query()->orderBy('source_entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'attachments' => AttachmentDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'forms' => DynamicFormDefinition::query()->orderBy('entity_type')->orderBy('flow')->orderBy('sort_order')->orderBy('name')->limit(80)->get(),
            'formFields' => DynamicFormFieldRule::query()->with('form:id,entity_type,code,name')->orderBy('dynamic_form_definition_id')->orderBy('sort_order')->limit(80)->get(),
            'documentTemplates' => DynamicDocumentTemplate::query()->with('branch:id,name')->orderBy('document_type')->orderByDesc('is_default')->orderBy('name')->limit(80)->get(),
            'reportTemplates' => DynamicReportTemplate::query()->orderBy('module')->orderBy('name')->limit(80)->get(),
            'calculationFormulas' => CalculationFormula::query()->orderBy('entity_type')->orderBy('name')->limit(80)->get(),
            'customFields' => CustomFieldDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'workflows' => WorkflowDefinition::query()->orderBy('entity_type')->orderByDesc('is_default')->orderBy('name')->limit(80)->get(),
            'states' => BusinessStateDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'workflowTransitions' => WorkflowTransition::query()->with('workflow:id,entity_type,code,name')->orderBy('workflow_definition_id')->orderBy('sort_order')->limit(80)->get(),
            'resources' => ReservableResource::query()->with(['branch:id,name', 'assignedWorker:id,name,branch_id'])->latest('updated_at')->limit(80)->get(),
            'priceLists' => PriceList::query()->with('branch:id,name')->latest('updated_at')->limit(80)->get(),
            'commissions' => CommissionRule::query()->with(['branch:id,name', 'product:id,name'])->latest('updated_at')->limit(80)->get(),
            'notifications' => NotificationRule::query()->latest('updated_at')->limit(80)->get(),
            'currencies' => BusinessCurrency::query()->orderByDesc('is_base')->orderBy('code')->limit(80)->get(),
            'printers' => PrinterProfile::query()->with('branch:id,name')->latest('updated_at')->limit(80)->get(),
            'licenses' => BusinessModuleLicense::query()->orderBy('module')->limit(80)->get(),
            'imports' => ImportProfileTemplate::query()->orderBy('entity_type')->orderBy('name')->limit(80)->get(),
            'branches' => Branch::query()->select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'workers' => Worker::query()->select('id', 'branch_id', 'name', 'position')->where('is_active', true)->orderBy('name')->limit(200)->get(),
            'products' => Product::query()->select('id', 'name')->where('is_active', true)->orderBy('name')->limit(120)->get(),
            'options' => $this->options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $options = BusinessTransversalConfiguration::options();
        $options['baseEntities'] = BusinessTransversalConfiguration::baseEntities();
        $options['entities'] = app(DynamicEntityRegistry::class)->options();
        $options['workflows'] = WorkflowDefinition::query()
            ->where('is_active', true)
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get(['id', 'entity_type', 'code', 'name'])
            ->map(fn (WorkflowDefinition $workflow) => [
                'id' => $workflow->id,
                'entity_type' => $workflow->entity_type,
                'code' => $workflow->code,
                'name' => $workflow->name.' ('.$workflow->entity_type.')',
            ])
            ->values()
            ->all();
        $options['forms'] = DynamicFormDefinition::query()
            ->where('is_active', true)
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get(['id', 'entity_type', 'code', 'name'])
            ->map(fn (DynamicFormDefinition $form) => [
                'id' => $form->id,
                'entity_type' => $form->entity_type,
                'code' => $form->code,
                'name' => $form->name.' ('.$form->entity_type.')',
            ])
            ->values()
            ->all();

        return $options;
    }
}
