import { reactive } from 'vue';
import axios from 'axios';

// Shared «المحفوظات» state: which files and folders are saved, toggled
// without reloading the page.
const state = reactive({ files: new Set(), folders: new Set() });

export function useSaved() {
    function load(ids) {
        state.files = new Set(ids?.files ?? []);
        state.folders = new Set(ids?.folders ?? []);
    }
    function isSaved(type, id) {
        return (type === 'folder' ? state.folders : state.files).has(id);
    }
    async function toggle(type, id) {
        const set = type === 'folder' ? state.folders : state.files;
        const was = set.has(id);
        // Optimistic, like Instagram; put back if the server refuses.
        was ? set.delete(id) : set.add(id);
        try {
            const { data } = await axios.post(route('saved.toggle'), { type, id }, { headers: { Accept: 'application/json' } });
            data.saved ? set.add(id) : set.delete(id);
            return data.saved;
        } catch (e) {
            was ? set.add(id) : set.delete(id);
            throw e;
        }
    }
    return { state, load, isSaved, toggle };
}
