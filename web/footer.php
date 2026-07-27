<script>
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('open');
        document.querySelector('.sidebar-overlay').classList.toggle('active');
    }

    function openProfileModal() {
        document.getElementById("profileModal").style.display = "flex";
    }

    function closeProfileModal() {
        document.getElementById("profileModal").style.display = "none";
    }

    window.addEventListener("click", function (e) {
        const modal = document.getElementById("profileModal");
        if (e.target === modal) {
            closeProfileModal();
        }
    });

    document.querySelectorAll('.nav-list a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                document.querySelector('.sidebar').classList.remove('open');
                document.querySelector('.sidebar-overlay').classList.remove('active');
            }
        });
    });

    // Convert tables to stacked responsive cards on small screens by adding data-labels
    function makeTablesResponsive() {
        document.querySelectorAll('.table-wrap table').forEach(table => {
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim());
            table.querySelectorAll('tbody tr').forEach(row => {
                Array.from(row.children).forEach((cell, index) => {
                    if (cell.tagName.toLowerCase() !== 'td' && cell.tagName.toLowerCase() !== 'th') return;
                    const label = headers[index] || '';
                    cell.setAttribute('data-label', label);
                });
            });
        });
    }

    // Debounced resize handler
    let resizeTimer = null;
    function onResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            makeTablesResponsive();
        }, 150);
    }

    document.addEventListener('DOMContentLoaded', function () {
        makeTablesResponsive();
        window.addEventListener('resize', onResize);
    });
</script>
</body>

</html>