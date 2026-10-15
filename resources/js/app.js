import './bootstrap';
import { start as startTurbo } from '@hotwired/turbo';

const directoryPaginationRequests = new Map();

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
