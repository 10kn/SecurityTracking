<?php

namespace App\Enums;

enum RuntimeSource: string
{
    case Manual = 'manual';
    case RuntimeBinary = 'runtime_binary';
    case ImageEnv = 'image_env';
    case DistroPackage = 'distro_package';
    case Declared = 'declared';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Nhập tay'),
            self::RuntimeBinary => __('Binary đang chạy'),
            self::ImageEnv => __('Biến môi trường image'),
            self::DistroPackage => __('Package của distro'),
            self::Declared => __('Khai báo trong repo'),
        };
    }
}
