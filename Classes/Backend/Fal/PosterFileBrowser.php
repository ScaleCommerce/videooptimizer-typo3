<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Fal;

use ScaleCommerce\VideoOptimizer\Backend\Input\InputException;
use ScaleCommerce\VideoOptimizer\Backend\Input\UploadRequest;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Lets an editor pick a poster image from the TYPO3 file list (FAL). Everything is checked
 * against the editor's own storages and file mounts.
 */
final class PosterFileBrowser
{
    private const MAX_ENTRIES = 500;

    public function __construct(private readonly ResourceFactory $resourceFactory) {}

    /**
     * Subfolders and poster-capable images (jpg/png/webp) of a folder; the storages without a folder.
     *
     * @return array{folder: string, parent: ?string, folders: list<array{id: string, name: string}>, files: list<array{id: string, name: string, size: int}>}
     */
    public function browse(BackendUserAuthentication $user, string $folderId): array
    {
        if (trim($folderId) === '') {
            $folders = [];
            foreach ($user->getFileStorages() as $storage) {
                try {
                    if ($storage->isBrowsable()) {
                        $folders[] = ['id' => $storage->getRootLevelFolder()->getCombinedIdentifier(), 'name' => $storage->getName()];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            return ['folder' => '', 'parent' => null, 'folders' => $folders, 'files' => []];
        }

        $folder = $this->folder($user, $folderId);
        $storage = $folder->getStorage();
        $folders = [];
        foreach (array_slice($storage->getFoldersInFolder($folder, 0, 0, true, false, 'name'), 0, self::MAX_ENTRIES) as $sub) {
            $folders[] = ['id' => $sub->getCombinedIdentifier(), 'name' => $sub->getName()];
        }
        $files = [];
        foreach ($storage->getFilesInFolder($folder, 0, 0, true, false, 'name') as $file) {
            if (UploadRequest::posterContentTypeForFilename($file->getName()) === null
                || $file->getSize() <= 0 || $file->getSize() > UploadRequest::MAX_POSTER_BYTES
            ) {
                continue;
            }
            $files[] = ['id' => $file->getCombinedIdentifier(), 'name' => $file->getName(), 'size' => $file->getSize()];
            if (count($files) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return ['folder' => $folder->getCombinedIdentifier(), 'parent' => $this->parentId($folder), 'folders' => $folders, 'files' => $files];
    }

    /**
     * The chosen file when it is a readable poster image of one of the editor's storages.
     *
     * @return array{file: File, contentType: string, size: int}
     */
    public function resolve(BackendUserAuthentication $user, string $fileId): array
    {
        try {
            $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier(trim($fileId));
        } catch (\Throwable) {
            $file = null;
        }
        if (!$file instanceof File || !$file->getStorage()->checkFileActionPermission('read', $file) || !$this->storageAllowed($user, $file->getStorage())) {
            throw new InputException('flash.poster.fileNotFound');
        }
        $contentType = UploadRequest::posterContentTypeForFilename($file->getName());
        if ($contentType === null) {
            throw new InputException('flash.poster.badType');
        }
        $size = $file->getSize();
        if ($size <= 0 || $size > UploadRequest::MAX_POSTER_BYTES) {
            throw new InputException('flash.poster.badSize');
        }

        return ['file' => $file, 'contentType' => $contentType, 'size' => $size];
    }

    private function folder(BackendUserAuthentication $user, string $folderId): Folder
    {
        try {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier(trim($folderId));
        } catch (\Throwable) {
            throw new InputException('flash.poster.folderNotFound');
        }
        $storage = $folder->getStorage();
        if (!$this->storageAllowed($user, $storage) || !$storage->isWithinFileMountBoundaries($folder)) {
            throw new InputException('flash.poster.folderNotFound');
        }

        return $folder;
    }

    private function parentId(Folder $folder): string
    {
        try {
            $parent = $folder->getParentFolder();
            if ($parent->getIdentifier() !== $folder->getIdentifier() && $folder->getStorage()->isWithinFileMountBoundaries($parent)) {
                return $parent->getCombinedIdentifier();
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function storageAllowed(BackendUserAuthentication $user, ResourceStorage $storage): bool
    {
        foreach ($user->getFileStorages() as $allowed) {
            if ($allowed->getUid() === $storage->getUid()) {
                return true;
            }
        }

        return false;
    }
}
