class CompactDatepicker {
    constructor(input) {
        this.input = input;
        this.datepicker = null;
        this.currentDate = new Date();
        this.focusedButton = null;
        this.isOpen = false;

        if (!this.input.placeholder) this.input.placeholder = "dd-mm-yyyy";
        this.input.autocomplete = "off";
        this.input.setAttribute('role', 'combobox');
        this.input.setAttribute('aria-expanded', 'false');
        this.input.setAttribute('aria-haspopup', 'dialog');

        if (input.value.trim()) this.setCurrentDateFromInput();

        this.initializeEventListeners();
    }

    initializeEventListeners() {
        // Input click event
        this.input.addEventListener('click', () => this.showDatepicker());

        // Global click handler for closing datepicker
        document.addEventListener('click', (e) => {
            if (this.isOpen && !this.input.contains(e.target) && !this.datepicker?.contains(e.target)) {
                this.hideDatepicker();
            }
        });

        // Keydown handler
        this.input.addEventListener('keydown', (e) => this.handleInputKeydown(e));

        // Paste handler
        this.input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            const formatted = this.normalizePastedDate(pasted);
            this.input.value = formatted;
            this.input.dispatchEvent(new Event('input'));
        });

        // Input handler
        let lastValue = "";
        this.input.addEventListener('input', () => this.handleInputChange());

        // Focus handler
        this.input.addEventListener('focus', () => {
            if (!this.isOpen) {
                this.showDatepicker();
            }
        });
    }

    handleInputKeydown(e) {
        const val = this.input.value.trim();
        const regex = /^(\d{2})-(\d{2})-(\d{4})$/;

        switch (e.key) {
            case 'Enter':
                if (this.isOpen) {
                    if (this.focusedButton) {
                        this.focusedButton.click();
                    } else if (regex.test(val)) {
                        this.hideDatepicker();
                    } else if (val === '') {
                        const t = new Date();
                        this.input.value = `${String(t.getDate()).padStart(2, '0')}-${String(t.getMonth() + 1).padStart(2, '0')}-${t.getFullYear()}`;
                        this.input.dispatchEvent(new Event('input'));
                        this.hideDatepicker();
                    } else {
                        this.input.style.border = "2px solid red";
                    }
                    e.preventDefault();
                }
                break;

            case 'Escape':
                if (this.isOpen) {
                    this.hideDatepicker();
                    e.preventDefault();
                }
                break;

            case 'Tab':
                if (this.isOpen) {
                    this.hideDatepicker();
                }
                break;

            case 'ArrowDown':
                if (this.isOpen) {
                    if (!this.focusedButton) {
                        this.focusFirstDate();
                    } else {
                        this.handleKeyboard(e);
                    }
                    e.preventDefault();
                } else {
                    this.showDatepicker();
                    e.preventDefault();
                }
                break;

            case 'ArrowUp':
            case 'ArrowLeft':
            case 'ArrowRight':
                if (this.isOpen) {
                    if (!this.focusedButton) {
                        this.focusFirstDate();
                    } else {
                        this.handleKeyboard(e);
                    }
                    e.preventDefault();
                }
                break;

            default:
                break;
        }
    }

    handleInputChange() {
        let val = this.convertBnToEnDigits(this.input.value);
        val = val.replace(/\D/g, '');

        let formatted = '';
        if (val.length > 0) formatted = val.slice(0, 2);
        if (val.length >= 3) formatted += '-' + val.slice(2, 4);
        if (val.length >= 5) formatted += '-' + val.slice(4, 8);

        this.input.value = formatted;

        const regex = /^(\d{2})-(\d{2})-(\d{4})$/;
        if (regex.test(formatted)) {
            const [_, dd, mm, yyyy] = formatted.match(regex);
            const parsed = new Date(`${yyyy}-${mm}-${dd}`);
            const valid =
                !isNaN(parsed) &&
                parsed.getDate() === parseInt(dd) &&
                parsed.getMonth() + 1 === parseInt(mm) &&
                parsed.getFullYear() === parseInt(yyyy);

            this.input.style.border = valid ? "2px solid green" : "2px solid red";
            
            if (valid) {
                this.currentDate = parsed;
                if (this.datepicker) this.renderCalendar(this.currentDate);
            }
        } else {
            this.input.style.border = '';
        }
    }

    convertBnToEnDigits(str) {
        const map = {
            '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4',
            '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9'
        };
        return str.replace(/[০-৯]/g, d => map[d]);
    }

    normalizePastedDate(str) {
        str = this.convertBnToEnDigits(str.trim());

        // yyyy-mm-dd or yyyy/mm/dd
        let m = str.match(/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/);
        if (m) return `${m[3]}-${m[2]}-${m[1]}`;

        // dd-mm-yyyy or dd/mm/yyyy
        m = str.match(/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/);
        if (m) return `${m[1]}-${m[2]}-${m[3]}`;

        // mm-dd-yyyy or mm/dd/yyyy (US format)
        m = str.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/);
        if (m && parseInt(m[1]) <= 12) {
            return `${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}-${m[3]}`;
        }

        // 8 digits only
        m = str.match(/^(\d{8})$/);
        if (m) {
            const v = m[1];
            if (v.startsWith('19') || v.startsWith('20')) {
                return `${v.slice(6, 8)}-${v.slice(4, 6)}-${v.slice(0, 4)}`;
            }
            return `${v.slice(0, 2)}-${v.slice(2, 4)}-${v.slice(4, 8)}`;
        }

        return str;
    }

    setCurrentDateFromInput() {
        const regex = /^(\d{2})-(\d{2})-(\d{4})$/;
        const match = this.input.value.match(regex);
        if (match) {
            const [_, dd, mm, yyyy] = match;
            const parsed = new Date(`${yyyy}-${mm}-${dd}`);
            if (!isNaN(parsed)) {
                this.currentDate = parsed;
            }
        }
    }

    showDatepicker() {
        if (this.datepicker) return;

        this.datepicker = document.createElement('div');
        this.datepicker.className = 'vs-datepicker';
        this.datepicker.setAttribute('role', 'dialog');
        this.datepicker.setAttribute('aria-label', 'Choose date');
        document.body.appendChild(this.datepicker);

        this.isOpen = true;
        this.input.setAttribute('aria-expanded', 'true');

        if (this.input.value.trim()) {
            this.setCurrentDateFromInput();
        } else {
            this.currentDate = new Date();
        }

        this.renderCalendar(this.currentDate);
        this.positionDatepicker();

        requestAnimationFrame(() => {
            this.focusFirstDate();
        });
    }

    positionDatepicker() {
        requestAnimationFrame(() => {
            const rect = this.input.getBoundingClientRect();
            const dpHeight = this.datepicker.offsetHeight;
            const dpWidth = this.datepicker.offsetWidth;
            const scrollY = window.scrollY || window.pageYOffset;
            const scrollX = window.scrollX || window.pageXOffset;
            const viewportHeight = window.innerHeight;
            const viewportWidth = window.innerWidth;

            const spaceBelow = viewportHeight - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow >= dpHeight + 8) {
                this.datepicker.style.top = `${rect.bottom + scrollY + 4}px`;
            } else if (spaceAbove >= dpHeight + 8) {
                this.datepicker.style.top = `${rect.top + scrollY - dpHeight - 4}px`;
            } else {
                this.datepicker.style.top = `${rect.bottom + scrollY + 4}px`;
            }

            let leftPos = rect.left + scrollX;
            if (leftPos + dpWidth > viewportWidth + scrollX) {
                leftPos = viewportWidth + scrollX - dpWidth - 8;
            }
            if (leftPos < scrollX) {
                leftPos = scrollX + 8;
            }
            this.datepicker.style.left = `${leftPos}px`;
        });
    }

    hideDatepicker() {
        if (this.datepicker) {
            this.datepicker.remove();
            this.datepicker = null;
            this.focusedButton = null;
            this.isOpen = false;
            this.input.setAttribute('aria-expanded', 'false');
        }
    }

    handleKeyboard(e) {
        if (!this.focusedButton) return;

        const buttons = Array.from(this.datepicker.querySelectorAll('td button:not(:disabled)'));
        const idx = buttons.indexOf(this.focusedButton);
        if (idx === -1) return;

        let newIdx = idx;

        switch (e.key) {
            case 'ArrowRight':
                newIdx = (idx + 1) % buttons.length;
                break;
            case 'ArrowLeft':
                newIdx = (idx - 1 + buttons.length) % buttons.length;
                break;
            case 'ArrowDown':
                newIdx = (idx + 7 < buttons.length ? idx + 7 : idx);
                break;
            case 'ArrowUp':
                newIdx = (idx - 7 >= 0 ? idx - 7 : idx);
                break;
            case 'Enter':
            case ' ':
                this.focusedButton.click();
                e.preventDefault();
                return;
            case 'Escape':
                this.hideDatepicker();
                this.input.focus();
                e.preventDefault();
                return;
            case 'Home':
                newIdx = 0;
                break;
            case 'End':
                newIdx = buttons.length - 1;
                break;
            case 'PageUp':
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                this.renderCalendar(this.currentDate);
                this.focusFirstDate();
                e.preventDefault();
                return;
            case 'PageDown':
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                this.renderCalendar(this.currentDate);
                this.focusFirstDate();
                e.preventDefault();
                return;
            default:
                return;
        }

        if (newIdx !== idx) {
            this.focusedButton = buttons[newIdx];
            this.focusedButton.focus();
            e.preventDefault();
        }
    }

    focusFirstDate() {
        const buttons = Array.from(this.datepicker.querySelectorAll('td button:not(:disabled)'));
        if (buttons.length) {
            const inputDate = this.input.value.trim();
            let targetBtn = null;

            if (inputDate) {
                const regex = /^(\d{2})-(\d{2})-(\d{4})$/;
                const match = inputDate.match(regex);
                if (match) {
                    const [_, dd, mm, yyyy] = match;
                    targetBtn = buttons.find(btn => {
                        const btnDate = btn.dataset.date;
                        return btnDate === inputDate;
                    });
                }
            }

            if (!targetBtn) {
                const today = new Date();
                const todayStr = `${String(today.getDate()).padStart(2, '0')}-${String(today.getMonth() + 1).padStart(2, '0')}-${today.getFullYear()}`;
                targetBtn = buttons.find(btn => btn.dataset.date === todayStr) || buttons[0];
            }

            this.focusedButton = targetBtn;
            this.focusedButton.focus();
        }
    }

    renderCalendar(date) {
        this.datepicker.innerHTML = '';
        const month = date.getMonth();
        const year = date.getFullYear();

        // Header
        const header = document.createElement('div');
        header.className = 'header';

        const leftArrow = document.createElement('button');
        leftArrow.innerHTML = '&#9664;';
        leftArrow.type = 'button';
        leftArrow.className = 'month-arrow';
        leftArrow.setAttribute('aria-label', 'Previous month');
        leftArrow.addEventListener('click', (e) => {
            e.stopPropagation();
            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
            this.renderCalendar(this.currentDate);
            this.focusFirstDate();
        });
        header.appendChild(leftArrow);

        const monthSelect = document.createElement('select');
        monthSelect.setAttribute('aria-label', 'Select month');
        const yearSelect = document.createElement('select');
        yearSelect.setAttribute('aria-label', 'Select year');

        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        for (let m = 0; m < 12; m++) {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = monthNames[m];
            if (m === month) opt.selected = true;
            monthSelect.appendChild(opt);
        }

        const currentYear = new Date().getFullYear();
        for (let y = 1935; y <= currentYear + 10; y++) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (y === year) opt.selected = true;
            yearSelect.appendChild(opt);
        }

        monthSelect.addEventListener('change', (e) => {
            e.stopPropagation();
            this.currentDate.setMonth(parseInt(monthSelect.value));
            this.renderCalendar(this.currentDate);
            this.focusFirstDate();
        });

        yearSelect.addEventListener('change', (e) => {
            e.stopPropagation();
            this.currentDate.setFullYear(parseInt(yearSelect.value));
            this.renderCalendar(this.currentDate);
            this.focusFirstDate();
        });

        header.appendChild(monthSelect);
        header.appendChild(yearSelect);

        const rightArrow = document.createElement('button');
        rightArrow.innerHTML = '&#9654;';
        rightArrow.type = 'button';
        rightArrow.className = 'month-arrow';
        rightArrow.setAttribute('aria-label', 'Next month');
        rightArrow.addEventListener('click', (e) => {
            e.stopPropagation();
            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
            this.renderCalendar(this.currentDate);
            this.focusFirstDate();
        });
        header.appendChild(rightArrow);

        this.datepicker.appendChild(header);

        // Calendar table
        const table = document.createElement('table');
        table.setAttribute('role', 'grid');
        const thead = document.createElement('thead');
        const trHead = document.createElement('tr');
        trHead.setAttribute('role', 'row');

        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(d => {
            const th = document.createElement('th');
            th.textContent = d;
            th.setAttribute('role', 'columnheader');
            th.setAttribute('aria-label', d);
            trHead.appendChild(th);
        });
        thead.appendChild(trHead);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        const todayStr = `${String(today.getDate()).padStart(2, '0')}-${String(today.getMonth() + 1).padStart(2, '0')}-${today.getFullYear()}`;

        let tr = document.createElement('tr');
        tr.setAttribute('role', 'row');

        for (let i = 0; i < firstDay; i++) {
            const td = document.createElement('td');
            td.setAttribute('role', 'gridcell');
            tr.appendChild(td);
        }

        for (let day = 1; day <= daysInMonth; day++) {
            if (tr.children.length === 7) {
                tbody.appendChild(tr);
                tr = document.createElement('tr');
                tr.setAttribute('role', 'row');
            }

            const td = document.createElement('td');
            td.setAttribute('role', 'gridcell');
            const btn = document.createElement('button');
            btn.textContent = day;
            btn.type = 'button';
            btn.setAttribute('tabindex', '-1');

            const formattedDate = `${String(day).padStart(2, '0')}-${String(month + 1).padStart(2, '0')}-${year}`;
            btn.dataset.date = formattedDate;
            btn.setAttribute('aria-label', `${day} ${monthNames[month]} ${year}`);

            if (this.input.value.trim() && this.input.value === formattedDate) {
                btn.classList.add('selected');
                btn.setAttribute('aria-pressed', 'true');
            }

            if (formattedDate === todayStr) {
                btn.classList.add('today');
            }

            btn.addEventListener('click', () => {
                this.selectDate(new Date(year, month, day));
            });

            td.appendChild(btn);
            tr.appendChild(td);
        }

        if (tr.children.length > 0) tbody.appendChild(tr);
        table.appendChild(tbody);
        this.datepicker.appendChild(table);

        // Footer with buttons
        const footer = document.createElement('div');
        footer.className = 'footer';

        const todayBtn = document.createElement('button');
        todayBtn.textContent = 'Today';
        todayBtn.className = 'today-btn';
        todayBtn.type = 'button';
        todayBtn.setAttribute('aria-label', 'Select today');
        todayBtn.addEventListener('click', () => {
            this.selectDate(new Date());
        });
        footer.appendChild(todayBtn);

        const clearBtn = document.createElement('button');
        clearBtn.innerHTML = '&#10005;'; // × symbol
        clearBtn.className = 'clear-btn';
        clearBtn.type = 'button';
        clearBtn.setAttribute('aria-label', 'Clear date');
        clearBtn.setAttribute('title', 'Clear date');
        clearBtn.addEventListener('click', () => {
            this.input.value = '';
            this.input.style.border = '';
            this.input.dispatchEvent(new Event('input'));
            this.input.dispatchEvent(new Event('change'));
            this.hideDatepicker();
        });
        footer.appendChild(clearBtn);

        this.datepicker.appendChild(footer);
    }

    selectDate(d) {
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        this.input.value = `${day}-${month}-${year}`;
        this.input.dispatchEvent(new Event('input'));
        this.input.dispatchEvent(new Event('change'));
        this.hideDatepicker();
        this.input.focus();
    }

    destroy() {
        this.hideDatepicker();
    }

    setDate(date) {
        if (date instanceof Date && !isNaN(date)) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            this.input.value = `${day}-${month}-${year}`;
            this.input.dispatchEvent(new Event('input'));
            this.currentDate = date;
        }
    }

    getDate() {
        const regex = /^(\d{2})-(\d{2})-(\d{4})$/;
        const match = this.input.value.match(regex);
        if (match) {
            const [_, dd, mm, yyyy] = match;
            return new Date(`${yyyy}-${mm}-${dd}`);
        }
        return null;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.datepicker').forEach(input => {
        const datepicker = new CompactDatepicker(input);
        input.datepickerInstance = datepicker;
    });
});