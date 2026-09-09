import { reactive } from 'vue';

/**
 * Tracks one or more concurrent uploads, each split into fixed-size chunks
 * so large files never hit PHP's upload_max_filesize / post_max_size and
 * a dropped connection only has to retry the last chunk (mirrors VFM's
 * "chunked uploads using the HTML5 file API" behaviour).
 */
export function useChunkedUpload({ onUploaded } = {}) {
    const uploads = reactive([]);

    async function upload(file, folderId) {
        const entry = reactive({
            id: `${file.name}-${Date.now()}`,
            name: file.name,
            progress: 0,
            status: 'uploading',
            error: null,
        });
        uploads.push(entry);

        try {
            const { data: init } = await axios.post(route('uploads.init'), {
                name: file.name,
                size: file.size,
                folder_id: folderId,
            });

            const { identifier, chunk_size, total_chunks } = init;

            for (let index = 0; index < total_chunks; index++) {
                const start = index * chunk_size;
                const blob = file.slice(start, start + chunk_size);
                const form = new FormData();
                form.append('identifier', identifier);
                form.append('index', index);
                form.append('chunk', blob);

                await axios.post(route('uploads.chunk'), form, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                entry.progress = Math.round(((index + 1) / total_chunks) * 100);
            }

            const { data } = await axios.post(route('uploads.finalize'), { identifier });

            entry.status = 'done';
            onUploaded?.(data.file);
        } catch (e) {
            entry.status = 'error';
            entry.error = e.response?.data?.message ?? 'فشل رفع الملف.';
        }
    }

    function uploadMany(files, folderId) {
        return Promise.all(Array.from(files).map((file) => upload(file, folderId)));
    }

    function clearFinished() {
        for (let i = uploads.length - 1; i >= 0; i--) {
            if (uploads[i].status !== 'uploading') {
                uploads.splice(i, 1);
            }
        }
    }

    return { uploads, uploadMany, clearFinished };
}
