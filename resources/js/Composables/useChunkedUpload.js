import { reactive } from 'vue';

const STORAGE_KEY = 'filenest_pending_uploads';

function readRegistry() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
}

function writeRegistry(registry) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(registry));
    } catch {
        // localStorage unavailable (private browsing, quota) — resuming
        // across a reload just won't be offered; in-session pause/resume
        // and retry-on-failure still work without it.
    }
}

function rememberPending(identifier, { name, size, folderId }) {
    const registry = readRegistry();
    registry[identifier] = { name, size, folderId, startedAt: Date.now() };
    writeRegistry(registry);
}

function forgetPending(identifier) {
    const registry = readRegistry();
    delete registry[identifier];
    writeRegistry(registry);
}

/**
 * Tracks one or more concurrent uploads, each split into fixed-size chunks
 * so large files never hit PHP's upload_max_filesize / post_max_size and
 * a dropped connection only has to retry the last chunk (mirrors VFM's
 * "chunked uploads using the HTML5 file API" behaviour), plus:
 *
 *  - a visible per-file progress bar with pause/resume/cancel controls;
 *  - automatic retry of a single failed chunk before giving up;
 *  - resuming an upload across a page reload/crash, by re-selecting the
 *    same file — the backend recognises the (name, size, folder) triple
 *    and hands back whichever chunks it already has, so only the missing
 *    ones are re-sent.
 */
export function useChunkedUpload({ onUploaded } = {}) {
    const uploads = reactive([]);

    // Snapshot of unfinished uploads left behind by a previous page load,
    // detected once at composable creation time (i.e. on page load).
    const interrupted = reactive(
        Object.entries(readRegistry()).map(([identifier, meta]) => ({ identifier, ...meta })),
    );

    function removeInterrupted(identifier) {
        const index = interrupted.findIndex((item) => item.identifier === identifier);
        if (index !== -1) interrupted.splice(index, 1);
    }

    async function uploadChunkWithRetry(entry, index, blob, attempt = 1) {
        const form = new FormData();
        form.append('identifier', entry.identifier);
        form.append('index', index);
        form.append('chunk', blob);

        try {
            await axios.post(route('uploads.chunk'), form, {
                headers: { 'Content-Type': 'multipart/form-data' },
                signal: entry.controller.signal,
            });
        } catch (e) {
            if (axios.isCancel(e) || attempt >= 3) throw e;
            await new Promise((resolve) => setTimeout(resolve, attempt * 1000));
            return uploadChunkWithRetry(entry, index, blob, attempt + 1);
        }
    }

    /**
     * Always starts (or continues) an upload by calling init() — the
     * backend transparently resumes a still-in-progress session for the
     * same (name, size, folder) instead of starting over, so this single
     * code path handles a brand-new upload, clicking "resume" after a
     * pause/error, and reattaching after a page reload alike.
     */
    async function runUpload(entry) {
        entry.status = 'uploading';
        entry.error = null;
        entry.controller = new AbortController();

        try {
            const { data: init } = await axios.post(
                route('uploads.init'),
                { name: entry.file.name, size: entry.file.size, folder_id: entry.folderId },
                { signal: entry.controller.signal },
            );

            entry.identifier = init.identifier;
            entry.totalChunks = init.total_chunks;
            const uploaded = new Set(init.received_chunks ?? []);
            entry.progress = Math.round((uploaded.size / init.total_chunks) * 100);

            rememberPending(entry.identifier, {
                name: entry.file.name,
                size: entry.file.size,
                folderId: entry.folderId,
            });

            for (let index = 0; index < init.total_chunks; index++) {
                if (uploaded.has(index)) continue;
                if (entry.status !== 'uploading') return;

                const start = index * init.chunk_size;
                const blob = entry.file.slice(start, start + init.chunk_size);
                await uploadChunkWithRetry(entry, index, blob);

                uploaded.add(index);
                entry.progress = Math.round((uploaded.size / init.total_chunks) * 100);
            }

            if (entry.status !== 'uploading') return;

            const { data } = await axios.post(route('uploads.finalize'), { identifier: entry.identifier });

            entry.status = 'done';
            entry.progress = 100;
            forgetPending(entry.identifier);
            onUploaded?.(data.file);
        } catch (e) {
            if (entry.status === 'paused' || entry.status === 'cancelled') return;
            entry.status = 'error';
            entry.error = e.response?.data?.message ?? 'فشل رفع الملف. اضغط استئناف للمحاولة مجدداً.';
        }
    }

    function createEntry(file, folderId) {
        return reactive({
            id: `${file.name}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
            name: file.name,
            file,
            folderId,
            progress: 0,
            status: 'uploading',
            error: null,
            identifier: null,
            totalChunks: null,
            controller: null,
        });
    }

    function upload(file, folderId) {
        const entry = createEntry(file, folderId);
        uploads.push(entry);
        return runUpload(entry);
    }

    function uploadMany(files, folderId) {
        return Promise.all(Array.from(files).map((file) => upload(file, folderId)));
    }

    async function createRemoteFolder(name, parentId) {
        const { data } = await axios.post(
            route('folders.store'),
            { name, parent_id: parentId },
            { headers: { Accept: 'application/json' } },
        );
        return data.folder.id;
    }

    /**
     * Uploads a whole picked directory (from an <input webkitdirectory>
     * selection), recreating its subfolder structure under [rootFolderId]
     * before uploading each file into its resolved folder. Folder creation
     * reuses the same POST /folders endpoint as the "new folder" button, so
     * — just like it — a name colliding with an existing folder is
     * auto-renamed rather than merged: re-uploading the same directory
     * twice produces a sibling copy, not a merge into the original.
     *
     * Directories are resolved (and created) one at a time, in path order,
     * so two files that share a not-yet-created parent directory never
     * race to create it twice.
     */
    async function uploadFolderTree(fileList, rootFolderId) {
        const files = Array.from(fileList).filter((file) => file.webkitRelativePath);
        if (!files.length) return [];

        // Relative directory path ("" = the root folder itself) -> its id.
        const folderIds = new Map([['', rootFolderId]]);

        async function resolveFolder(path) {
            if (folderIds.has(path)) return folderIds.get(path);

            const lastSlash = path.lastIndexOf('/');
            const parentPath = lastSlash === -1 ? '' : path.slice(0, lastSlash);
            const name = lastSlash === -1 ? path : path.slice(lastSlash + 1);

            const parentId = await resolveFolder(parentPath);
            const id = await createRemoteFolder(name, parentId);
            folderIds.set(path, id);
            return id;
        }

        const started = [];
        for (const file of files) {
            const relativePath = file.webkitRelativePath;
            const lastSlash = relativePath.lastIndexOf('/');
            const dirPath = lastSlash === -1 ? '' : relativePath.slice(0, lastSlash);
            const folderId = await resolveFolder(dirPath);
            started.push(upload(file, folderId));
        }

        return Promise.all(started);
    }

    /** Cleanly stops an in-flight upload without losing its progress — the
     * session stays on the server so `resume()` can pick it back up. */
    function pause(entry) {
        if (entry.status !== 'uploading') return;
        entry.status = 'paused';
        entry.controller?.abort();
    }

    function resume(entry) {
        if (entry.status !== 'paused' && entry.status !== 'error') return;
        runUpload(entry);
    }

    function cancel(entry) {
        entry.status = 'cancelled';
        entry.controller?.abort();
        if (entry.identifier) {
            axios.post(route('uploads.cancel'), { identifier: entry.identifier }).catch(() => {});
            forgetPending(entry.identifier);
        }
        const index = uploads.indexOf(entry);
        if (index !== -1) uploads.splice(index, 1);
    }

    function clearFinished() {
        for (let i = uploads.length - 1; i >= 0; i--) {
            if (uploads[i].status === 'done' || uploads[i].status === 'cancelled') {
                uploads.splice(i, 1);
            }
        }
    }

    /**
     * The user re-selected a file to continue one of the `interrupted`
     * entries found on page load. Only the name+size need to match — the
     * backend is the source of truth for which chunks were already
     * received, so mismatched folders etc. simply resume into whatever the
     * server still has for that identifier.
     */
    function resumeInterrupted(pending, file, folderId) {
        if (file.name !== pending.name || file.size !== pending.size) {
            return false;
        }

        removeInterrupted(pending.identifier);
        const entry = createEntry(file, folderId ?? pending.folderId);
        uploads.push(entry);
        runUpload(entry);
        return true;
    }

    function dismissInterrupted(pending) {
        removeInterrupted(pending.identifier);
        forgetPending(pending.identifier);
    }

    return {
        uploads,
        uploadMany,
        uploadFolderTree,
        clearFinished,
        pause,
        resume,
        cancel,
        interrupted,
        resumeInterrupted,
        dismissInterrupted,
    };
}
