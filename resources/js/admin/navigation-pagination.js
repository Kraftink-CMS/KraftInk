document.addEventListener('pagination:after', (event) => {
    const location = event.target.id?.match(/^navigation-(.+)-list$/)?.[1];
    const tab = location ? document.getElementById(`navigation-tab-${location}`) : null;
    if (!tab || tab.classList.contains('active')) {
        return;
    }
    tab.click();
});
