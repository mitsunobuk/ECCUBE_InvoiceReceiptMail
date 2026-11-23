<?php

namespace Plugin\InvoiceReceiptMail\Service;

use Eccube\Common\EccubeConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 帳票に押す社印の画像を保管する。
 *
 * プラグインを更新・再インストールしても消えないよう、プラグインのフォルダではなく
 * app/PluginData/InvoiceReceiptMail/ に置く。
 */
class StampStorage
{
    private const EXTENSIONS = ['png', 'jpg'];

    /** @var string */
    private $dir;

    public function __construct(EccubeConfig $eccubeConfig)
    {
        $this->dir = $eccubeConfig->get('kernel.project_dir').'/app/PluginData/InvoiceReceiptMail';
    }

    public function path(): ?string
    {
        foreach (self::EXTENSIONS as $ext) {
            $path = $this->dir.'/stamp.'.$ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function save(UploadedFile $file): void
    {
        $ext = $file->guessExtension() === 'png' ? 'png' : 'jpg';
        $this->delete();
        (new Filesystem())->mkdir($this->dir);
        $file->move($this->dir, 'stamp.'.$ext);
    }

    public function delete(): void
    {
        foreach (self::EXTENSIONS as $ext) {
            (new Filesystem())->remove($this->dir.'/stamp.'.$ext);
        }
    }

    /**
     * 設定画面のプレビュー用
     */
    public function dataUri(): ?string
    {
        $path = $this->path();
        if ($path === null) {
            return null;
        }
        $mime = substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
