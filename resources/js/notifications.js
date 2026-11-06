// Look of each notification kind, and a short "time ago" in Arabic.
import {
    BookOpenIcon, BookmarkIcon, ClockIcon, ExclamationTriangleIcon, CheckCircleIcon, ArrowPathIcon,
    BanknotesIcon, FolderPlusIcon, ChatBubbleLeftRightIcon, ArrowUturnLeftIcon, ChatBubbleLeftEllipsisIcon, UserPlusIcon, BellIcon, ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline';

const KINDS = {
    loan_issued: [BookOpenIcon, 'sky'],
    returned: [CheckCircleIcon, 'green'],
    returned_fine: [BanknotesIcon, 'amber'],
    renewed: [ArrowPathIcon, 'sky'],
    due_soon: [ClockIcon, 'amber'],
    due_today: [ClockIcon, 'amber'],
    overdue: [ExclamationTriangleIcon, 'red'],
    hold_ready: [BookmarkIcon, 'green'],
    hold_expired: [BookmarkIcon, 'gray'],
    hold_placed: [BookmarkIcon, 'accent'],
    staff_summary: [ClipboardDocumentListIcon, 'accent'],
    folder_assigned: [FolderPlusIcon, 'accent'],
    video_comment: [ChatBubbleLeftEllipsisIcon, 'sky'],
    user_registered: [UserPlusIcon, 'green'],
    contact_message: [ChatBubbleLeftRightIcon, 'accent'],
    contact_reply: [ArrowUturnLeftIcon, 'green'],
};

export const TONES = {
    accent: 'bg-indigo-50 text-[var(--accent-600)] dark:bg-indigo-900/30',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400',
    green: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
    red: 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
    gray: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
};

export function kindLook(kind) {
    const [icon, tone] = KINDS[kind] ?? [BellIcon, 'gray'];
    return { icon, tone: TONES[tone] };
}

export function timeAgo(iso) {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'الآن';
    if (s < 3600) return `قبل ${Math.floor(s / 60)} د`;
    if (s < 86400) return `قبل ${Math.floor(s / 3600)} س`;
    if (s < 7 * 86400) return `قبل ${Math.floor(s / 86400)} يوم`;
    return new Date(iso).toLocaleDateString('ar-IQ', { timeZone: 'Asia/Baghdad', day: 'numeric', month: 'short' });
}
