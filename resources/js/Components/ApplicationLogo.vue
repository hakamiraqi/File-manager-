<template>
    <!-- FileNest mark: a folder holding an open book (files + library). -->
    <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="FileNest">
  <defs>
    <linearGradient id="fn-logo-bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#6366f1"/>
      <stop offset="1" stop-color="#4338ca"/>
    </linearGradient>
    <linearGradient id="fn-logo-fold" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/>
      <stop offset="1" stop-color="#e0e7ff"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#fn-logo-bg)"/>
  <!-- folder back with tab -->
  <path d="M96 150a30 30 0 0 1 30-30h92c10 0 19 5 25 13l18 25h125a30 30 0 0 1 30 30v20H96z" fill="#c7d2fe"/>
  <!-- folder front -->
  <path d="M86 214a26 26 0 0 1 26-26h288a26 26 0 0 1 26 26v156a30 30 0 0 1-30 30H116a30 30 0 0 1-30-30z" fill="url(#fn-logo-fold)"/>
  <!-- open book -->
  <path d="M256 262c-26-18-58-24-92-22v104c34-2 66 4 92 22z" fill="#4f46e5"/>
  <path d="M256 262c26-18 58-24 92-22v104c-34-2-66 4-92 22z" fill="#6366f1"/>
  <path d="M256 262v104" stroke="#e0e7ff" stroke-width="6" stroke-linecap="round"/>
    </svg>
</template>
