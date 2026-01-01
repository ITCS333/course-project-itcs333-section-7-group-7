// --- Global Data Store ---
let weeks = [];

// --- Element Selections ---
const weekForm = document.querySelector('#week-form');
const weeksTableBody = document.querySelector('#weeks-tbody');

// --- Functions ---

function createWeekRow(week) {
  const tr = document.createElement('tr');

  const titleTd = document.createElement('td');
  titleTd.textContent = week.title;

  const descTd = document.createElement('td');
  descTd.textContent = week.description;

  const actionsTd = document.createElement('td');

  const editBtn = document.createElement('button');
  editBtn.textContent = 'Edit';
  editBtn.classList.add('edit-btn');
  editBtn.dataset.id = week.id;

  const deleteBtn = document.createElement('button');
  deleteBtn.textContent = 'Delete';
  deleteBtn.classList.add('delete-btn');
  deleteBtn.dataset.id = week.id;

  actionsTd.appendChild(editBtn);
  actionsTd.appendChild(deleteBtn);

  tr.appendChild(titleTd);
  tr.appendChild(descTd);
  tr.appendChild(actionsTd);

  return tr;
}

function renderTable() {
  weeksTableBody.innerHTML = ''; // Clear table body

  weeks.forEach((week) => {
    const row = createWeekRow(week);
    weeksTableBody.appendChild(row);
  });
}

function handleAddWeek(event) {
  event.preventDefault();

  const titleInput = weekForm.querySelector('input[name="title"]');
  const startDateInput = weekForm.querySelector('input[name="start-date"]');
  const descriptionInput = weekForm.querySelector('textarea[name="description"]');
  const linksTextarea = weekForm.querySelector('textarea[name="week-links"]');

  const newWeek = {
    id: `week_${Date.now()}`,
    title: titleInput.value.trim(),
    startDate: startDateInput.value, // optional use
    description: descriptionInput.value.trim(),
    links: linksTextarea.value.split('\n').map(link => link.trim()).filter(link => link)
  };

  weeks.push(newWeek);
  renderTable();
  weekForm.reset();
}

function handleTableClick(event) {
  const target = event.target;

  if (target.classList.contains('delete-btn')) {
    const idToDelete = target.dataset.id;
    weeks = weeks.filter(week => week.id !== idToDelete);
    renderTable();
  }

  // (Optional) Handle edit later
}

async function loadAndInitialize() {
  try {
    const response = await fetch('weeks.json');
    const data = await response.json();
    weeks = data;

    renderTable();
    weekForm.addEventListener('submit', handleAddWeek);
    weeksTableBody.addEventListener('click', handleTableClick);
  } catch (error) {
    console.error('Error loading weeks:', error);
  }
}

// --- Initial Page Load ---
loadAndInitialize();
