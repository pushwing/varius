(() => {
    const copyToClipboard = async (text) => {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (!copied) {
            throw new Error('클립보드 복사를 지원하지 않는 브라우저입니다.');
        }
    };

    window.sharePlace = async (name, url, button) => {
        const title = `${name} · 파구스`;
        if (navigator.share) {
            try {
                await navigator.share({ title, text: `\n${name} 정보를 확인해보세요.`, url });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
            }
        }

        try {
            await copyToClipboard(url);
            if (button) {
                const originalLabel = button.textContent;
                button.textContent = '링크 복사됨';
                window.setTimeout(() => {
                    button.textContent = originalLabel;
                }, 2000);
            }
        } catch {
            window.prompt('공유 링크를 복사하세요.', url);
        }
    };
})();
