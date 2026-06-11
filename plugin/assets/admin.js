(function () {
  const copyButtons = document.querySelectorAll('[data-sc-copy]');
  copyButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-sc-copy');
      try {
        await navigator.clipboard.writeText(value || '');
        button.textContent = 'Copied';
      } catch (error) {
        button.textContent = 'Copy failed';
      }
    });
  });
})();
