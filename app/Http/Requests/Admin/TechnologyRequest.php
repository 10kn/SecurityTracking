<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReleaseSource;
use App\Models\Technology;
use App\Services\Catalog\EndoflifeDateClient;
use App\Support\Slug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class TechnologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $technology = $this->route('technology');
        $ignore = $technology instanceof Technology ? $technology : null;

        return [
            'endoflife_product' => [
                'required', 'string', 'max:100', 'regex:'.Technology::ENDOFLIFE_PRODUCT_PATTERN,
                Rule::unique(Technology::class)->ignore($ignore),
                $this->productExistsRule(),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:'.Slug::PATTERN, Rule::unique(Technology::class)->ignore($ignore)],
            'release_source' => ['required', Rule::enum(ReleaseSource::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'endoflife_product' => __('Mã sản phẩm trên endoflife.date'),
            'name' => __('Tên hiển thị'),
            'slug' => __('Slug'),
            'release_source' => __('Nguồn danh sách bản phát hành'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.'),
        ];
    }

    private function productExistsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match(Technology::ENDOFLIFE_PRODUCT_PATTERN, $value) !== 1) {
                return;
            }

            try {
                $exists = app(EndoflifeDateClient::class)->productLabel($value) !== null;
            } catch (Throwable) {
                $fail(__('Không kết nối được endoflife.date để kiểm tra mã sản phẩm. Thử lại sau.'));

                return;
            }

            if (! $exists) {
                $fail(__('endoflife.date không có sản phẩm ":product".', ['product' => $value]));
            }
        };
    }
}
