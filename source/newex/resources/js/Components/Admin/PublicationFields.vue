<template>
  <fieldset class="p-4 border rounded w-full" style="border-color:var(--ui-line);color:var(--ui-text)">
    <legend>{{ $t('Publication review') }}</legend>
    <label class="block mb-2">{{ $t('Publication status') }}
      <themed-select v-model="value.publication_status" class="form-input w-full">
        <option value="draft">{{ $t('Draft') }}</option>
        <option value="pending">{{ $t('Awaiting review') }}</option>
        <option value="published">{{ $t('Reviewed and published') }}</option>
      </themed-select>
    </label>
    <label class="block">{{ $t('Review basis and data source') }}
      <textarea v-model="value.publication_reference" rows="3" maxlength="2000" class="form-input w-full" />
    </label>
    <p class="text-sm">{{ $t('Verify the name, price units, description and performance source before publishing. Saving records the reviewer and time. Drafts are hidden from public listings.') }}</p>
    <p v-if="$page.props.errors.publication_revision" class="text-red-500" role="alert">{{ $page.props.errors.publication_revision }}</p>
    <details class="mt-3"><summary>{{ $t('Publication summary') }}</summary><p>{{ $t('Name') }}: {{ value.name || value.display_name || '—' }}</p><p>{{ $t('Publication status') }}: {{ $t(value.publication_status === 'published' ? 'Reviewed and published' : value.publication_status === 'pending' ? 'Awaiting review' : 'Draft') }}</p><p>{{ $t('Saved revision') }}: {{ value.publication_revision || 0 }}</p><p>{{ $t('Drafts and pending products are hidden from public listings. Existing orders are not changed by this content edit.') }}</p></details>
    <p v-if="error" class="text-red-500" role="alert">{{ error }}</p>
  </fieldset>
</template>
<script>
export default {props:{value:{type:Object,required:true},error:String}};
</script>
