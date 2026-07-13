const input = document.querySelector('#fileInput');
const dropZone = document.querySelector('#dropZone');
const fileStrip = document.querySelector('#fileStrip');
const uploadForm = document.querySelector('#uploadForm');

if (input && dropZone && fileStrip && uploadForm) {
    const maxFiles = Number.parseInt(input.dataset.maxFiles || '10', 10);
    const uploadButton = uploadForm.querySelector('button[type="submit"]');

    const renderSelection = () => {
        const files = [...input.files];
        const tooMany = files.length > maxFiles;
        fileStrip.hidden = files.length === 0;
        fileStrip.textContent = tooMany
            ? `单次最多上传 ${maxFiles} 张，当前选择了 ${files.length} 张`
            : files.length > 0
                ? `${files.length} 张图片 · ${files.map((file) => file.name).join(' / ')}`
                : '';
        fileStrip.classList.toggle('error-text', tooMany);
        if (uploadButton) {
            uploadButton.disabled = tooMany;
        }
    };

    input.addEventListener('change', renderSelection);
    ['dragenter', 'dragover'].forEach((eventName) => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('is-dragging'));
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('is-dragging'));
    });
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) {
        return;
    }

    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        const previous = button.textContent;
        const status = button.parentElement?.querySelector('[data-copy-status]');
        button.textContent = '已复制';
        if (status) {
            status.textContent = 'API 凭证已复制到剪贴板';
        }
        window.setTimeout(() => {
            button.textContent = previous;
            if (status) {
                status.textContent = '';
            }
        }, 1200);
    } catch {
        const status = button.parentElement?.querySelector('[data-copy-status]');
        if (status) {
            status.textContent = '自动复制失败，请手动复制';
        }
        window.prompt('复制内容', button.dataset.copy);
    }
});
