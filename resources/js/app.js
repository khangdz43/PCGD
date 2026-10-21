import './bootstrap';
import { start as startTurbo } from '@hotwired/turbo';

const directoryPaginationRequests = new Map();

function updateAcademicBlock(classInput) {
    const academicBlockInput = classInput.form?.querySelector('[name="education[academic_block]"]');
    if (!academicBlockInput) return;

    academicBlockInput.value = classInput.value.match(/\d+/)?.[0] || '';
}

function updateSchoolCode(form) {
    const codeInput = form.querySelector('#code');
    const nameInput = form.querySelector('#name');
    const locationPicker = form.querySelector('[data-location-picker]');
    if (!codeInput || !nameInput) return;

    const words = nameInput.value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[đĐ]/g, 'd')
        .trim()
        .split(/\s+/)
        .filter((word) => word && word.toLowerCase() !== 'truong');
    const abbreviation = words
        .map((word) => (word.match(/[a-zA-Z0-9]/) || [''])[0])
        .join('')
        .toUpperCase() || 'TR';
    const provinceCode = locationPicker?.querySelector('[data-province]')?.value || '';
    const communeCode = locationPicker?.querySelector('[data-commune]')?.value || '';
    const prefix = [provinceCode, communeCode].filter(Boolean).join('_');

    codeInput.value = `${prefix}${prefix ? '_' : ''}${abbreviation}`.slice(0, 50);
}

function schoolFormFor(target) {
    const form = target.closest('form');

    return form?.querySelector('[data-school-code-preview]') && form.querySelector('#name')
        ? form
        : null;
}

document.addEventListener('input', (event) => {
    if (event.target.matches('#current-class')) {
        updateAcademicBlock(event.target);
    }

    const form = schoolFormFor(event.target);
    if (form && event.target.matches('#name')) updateSchoolCode(form);
});

document.addEventListener('turbo:load', () => {
    document.querySelectorAll('#current-class').forEach(updateAcademicBlock);
    document.querySelectorAll('form').forEach((form) => {
        if (form.querySelector('[data-school-code-preview]') && form.querySelector('#name')) {
            updateSchoolCode(form);
        }
    });
});

document.addEventListener('change', (event) => {
    const form = schoolFormFor(event.target);
    if (form && event.target.matches('[data-province], [data-commune]')) {
        updateSchoolCode(form);
    }
});

async function loadDirectoryPage(results, url) {
    const resultsId = results.id;
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    directoryPaginationRequests.get(resultsId)?.abort();

    const controller = new AbortController();
    directoryPaginationRequests.set(resultsId, controller);
    results.setAttribute('aria-busy', 'true');
    results.classList.add('directory-results-loading');

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Content-Update': 'true',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (!response.ok) {
            throw new Error(`Không tải được danh sách (HTTP ${response.status}).`);
        }

        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updatedResults = page.getElementById(resultsId);
        if (!updatedResults) {
            throw new Error('Phản hồi phân trang không có nội dung danh sách.');
        }

        results.innerHTML = updatedResults.innerHTML;
        window.history.replaceState({}, '', `${url.pathname}${url.search}`);
        window.requestAnimationFrame(() => window.scrollTo(scrollX, scrollY));
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.error(`Không tải được trang của ${resultsId}:`, error);
            const status = document.getElementById(
                resultsId === 'village-results' ? 'village-filter-status' : 'school-filter-status',
            );
            if (status) status.textContent = 'Không tải được trang dữ liệu. Vui lòng thử lại.';
        }
    } finally {
        if (directoryPaginationRequests.get(resultsId) === controller) {
            directoryPaginationRequests.delete(resultsId);
            results.removeAttribute('aria-busy');
            results.classList.remove('directory-results-loading');
        }
    }
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('#village-results .pagination a[href], #school-results .pagination a[href]');
    if (!link || event.defaultPrevented || event.button !== 0
        || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const results = link.closest('#village-results, #school-results');
    loadDirectoryPage(results, new URL(link.href, window.location.href));
}, true);

document.addEventListener('change', (event) => {
    const select = event.target.closest(
        '#village-results nav[aria-label="Phân trang"] select[data-pagination-select], '
        + '#school-results nav[aria-label="Phân trang"] select[data-pagination-select]',
    );
    if (!select) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const results = select.closest('#village-results, #school-results');
    const url = new URL(select.form.action, window.location.href);
    new FormData(select.form).forEach((value, key) => {
        if (typeof value === 'string' && value !== '') {
            url.searchParams.set(key, value);
        }
    });
    loadDirectoryPage(results, url);
}, true);

startTurbo();
