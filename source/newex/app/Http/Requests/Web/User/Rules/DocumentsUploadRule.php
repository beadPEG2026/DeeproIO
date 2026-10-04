<?php

namespace App\Http\Requests\Web\User\Rules;

use App\Models\FileUpload\FileUpload;
use Illuminate\Contracts\Validation\Rule;

class DocumentsUploadRule implements Rule
{
    public $errorMessage = 'Documents were not uploaded';

    /**
     * Determine if the validation rule passes.
     *
     * @param  string $attribute
     * @param  mixed $value
     * @return bool
     */
    public function passes($attribute, $symbol)
    {
        $document = request()->get('document_type', false);
        $documents = request()->get('documents', false);

        $document_size = 1;

        if($document == "id" || $document == "residence_permit") {
            $document_size = 2;
        }

        if(count($documents) !== $document_size) {
            $this->errorMessage = __("Both sides of the document should be uploaded");
            return false;
        }

        foreach ($documents as $id) {

            if(!FileUpload::whereId(intval($id))->first()) {
                $this->errorMessage = __("Invalid files were attached");
                return false;
            }

        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __($this->errorMessage);
    }
}
