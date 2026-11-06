// Section colours shared by the plan editor and the 3D room (kept apart from three.js so pages
// load it without the 3D engine).
export const SECTION_COLORS = ['#2563eb', '#059669', '#d97706', '#db2777', '#7c3aed', '#0891b2', '#dc2626', '#65a30d', '#ea580c', '#4f46e5'];

export const sectionColor = (id) => (id ? SECTION_COLORS[(Number(id) * 7) % SECTION_COLORS.length] : '#64748b');
