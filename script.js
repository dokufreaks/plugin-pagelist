document.addEventListener('DOMContentLoaded', function () {
  //Get all tables with pagination class included
  const tables = document.querySelectorAll('.table-with-pagination'); 

  //Foreach table found in the wiki page
  tables.forEach(table => {
    const tableState = {
      tableRef: table,
      rowsRef: Array.from(table.getElementsByTagName('tr')).slice(1),
      currentPage: 0,
      rowsPerPage: Number(table.getAttribute('data-rowsperpage')) || 10,        //Number of rows per page
      buttonsPosition: table.getAttribute('data-buttonsposition') || 'bottom',  //Position of buttons navigator 
      buttonsWindow: Number(table.getAttribute('data-buttonwindow')) || 3,      //Number of max buttons in screen = buttonsWindow * 2 + 1 //Use -1 to disable
    };
    tableState.totalPages = Math.ceil(tableState.rowsRef.length / tableState.rowsPerPage);

    createPageButtons(tableState);  // Create the navigation buttons initially
    showPage(tableState);           // Set initial page state
  });

  function createPageButtons(tableState) {
    const { totalPages, buttonsPosition, tableRef } = tableState;
    const paginationTdContainer = document.createElement('td');
  
    //Create buttons nodes
    const buttonsRef = [];
    for (let i = 0; i < totalPages; i++) { 
      buttonsRef.push(document.createElement('button'));
    }

    //Set style and evento for each button
    for (let i = 0; i < totalPages; i++) {
      buttonsRef[i].textContent = i + 1;
      buttonsRef[i].classList.add('pagination-button');
      //Event on click button
      buttonsRef[i].addEventListener('click', () => {
        tableState.currentPage = i;
        showPage(tableState);               //Set page content
        setButtons(buttonsRef, tableState); //Set buttons state
      });
      paginationTdContainer.appendChild(buttonsRef[i]);
    }

    setButtons(buttonsRef, tableState); //Set buttons state

    paginationTdContainer.classList.add('pagination-buttons-container');
    paginationTdContainer.setAttribute('colspan', '99'); //to use all the width of the table
    const paginationTrContainer = document.createElement('tr');
    paginationTrContainer.appendChild(paginationTdContainer);

    // Append the navigation buttons in the table
    if (buttonsPosition == 'top') {
      tableRef.insertBefore(paginationTrContainer, tableRef.firstChild);
    } else {
      tableRef.appendChild(paginationTrContainer);
    }
  }

  //Set de buttons style and others elements of the navigation buttons
  function setButtons(buttonsRef, tableState) {
    const { currentPage, totalPages, buttonsWindow  } = tableState;

    for (let i = 0; i < totalPages; i++) { 
      
      //Set active class to the current page button
      buttonsRef[i].classList.toggle('active', i == currentPage);
      
      //Hidden extra buttons if buttonsWindow if set. Min value for buttonsWindow is 2.
      if (buttonsWindow >= 2 && i >= 1 && i < totalPages - 1) {
        const leftMargin = currentPage - buttonsWindow;
        const rightMargin = currentPage + buttonsWindow;
        const leftCompensation = leftMargin < 0 ? leftMargin : 0;
        const rightCompensation = rightMargin - totalPages >= 0 ? rightMargin - totalPages + 1 : 0;
  
        //Hide or reveal buttons base in current page
        if ( leftMargin - rightCompensation < i && rightMargin - leftCompensation > i) {
          buttonsRef[i].classList.toggle('hidden', false);
        } else {
          buttonsRef[i].classList.toggle('hidden', true);
        }
  
        //Hide or reveal space dots to limit the total number of buttons in screen
        if ((currentPage - rightCompensation == i + buttonsWindow - 1) && i >= buttonsWindow - 1) {
          deleteSpaceDots('left');
          drawSpaceDots(buttonsRef, i, 'left')
        } else if (currentPage - 1 < buttonsWindow) {
          deleteSpaceDots('left');
        }
        if ((currentPage - leftCompensation + buttonsWindow - 1 == i) && i <= totalPages - buttonsWindow) {
          deleteSpaceDots('right');
          drawSpaceDots(buttonsRef, i, 'right')
        } else if (currentPage + 1 >= totalPages - buttonsWindow) {
          deleteSpaceDots('right');
        }
      }
    }
  }

  //Draw space dots after or before a certain button
  function drawSpaceDots(buttonsRef, i, side) {
    const dotsRef = document.createElement('a');
    dotsRef.textContent = " ..... ";
    dotsRef.id = side == 'right' ? 'pagination_right_dots' : 'pagination_left_dots';
    buttonsRef[i].insertAdjacentElement(side == 'right' ? 'afterend' : 'beforebegin', dotsRef);
  }

  //Delete left or right space dots
  function deleteSpaceDots(side) {
    const spaceDotsRef = document.getElementById( side == 'right' ? 'pagination_right_dots' : 'pagination_left_dots');
    if (spaceDotsRef) spaceDotsRef.parentNode.removeChild(spaceDotsRef);
  }

  //Show and hide elements (rows) of a page
  function showPage(tableState) {
    const { currentPage, rowsPerPage, rowsRef } = tableState;
    const startIndex = currentPage * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    rowsRef.forEach((rowRef, index) => {
      rowRef.classList.toggle('hidden', index < startIndex || index >= endIndex);
    });
  }
});