<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

class ZipDownloadBuilder
{
    /**
     * Build a zip archive out of the given files and folders (with their
     * contents, recursively) and return the absolute path to the archive.
     *
     * @param  Collection<int, File>  $files
     * @param  Collection<int, Folder>  $folders
     */
    public function build(Collection $files, Collection $folders): string
    {
        $zipPath = storage_path('app/filemanager/tmp/'.Str::uuid().'.zip');

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            $this->addFile($zip, $file, $file->name);
        }

        foreach ($folders as $folder) {
            $this->addFolder($zip, $folder, $folder->name);
        }

        $zip->close();

        return $zipPath;
    }

    private function addFile(ZipArchive $zip, File $file, string $entryName): void
    {
        if ($file->disk()->exists($file->path)) {
            $zip->addFile($file->disk()->path($file->path), $entryName);
        }
    }

    private function addFolder(ZipArchive $zip, Folder $folder, string $entryPath): void
    {
        $zip->addEmptyDir($entryPath);

        foreach ($folder->files()->whereNull('deleted_at')->get() as $file) {
            $this->addFile($zip, $file, $entryPath.'/'.$file->name);
        }

        foreach ($folder->children()->whereNull('deleted_at')->get() as $child) {
            $this->addFolder($zip, $child, $entryPath.'/'.$child->name);
        }
    }
}
