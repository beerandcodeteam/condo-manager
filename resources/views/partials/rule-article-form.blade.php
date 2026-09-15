{{-- Inline article form of the Regimento screen (pages::rule-documents); expects $formId. --}}
<form id="{{ $formId }}" wire:submit="saveArticle" class="flex flex-col gap-3">
    <div class="grid grid-cols-[140px_1fr] gap-3">
        <x-ui.field label="Referência" name="articleReference" for="{{ $formId }}-reference">
            <x-ui.input id="{{ $formId }}-reference" wire:model="articleReference" maxlength="255" placeholder="Art. 23, §1º" />
        </x-ui.field>

        <x-ui.field label="Título (opcional)" name="articleTitle" for="{{ $formId }}-title">
            <x-ui.input id="{{ $formId }}-title" wire:model="articleTitle" maxlength="255" />
        </x-ui.field>
    </div>

    <x-ui.field label="Texto" name="articleBody" for="{{ $formId }}-body">
        <x-ui.textarea id="{{ $formId }}-body" wire:model="articleBody" rows="5" />
    </x-ui.field>

    <div class="flex items-center justify-end gap-2">
        <x-ui.button variant="secondary" size="sm" wire:click="cancelArticleForm">Cancelar</x-ui.button>
        <x-ui.button type="submit" size="sm" loading="saveArticle">Salvar</x-ui.button>
    </div>
</form>
