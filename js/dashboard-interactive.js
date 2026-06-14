/**
 * Dashboard Interactivity Module
 * Handles View All toggle for milestone cards and carousel navigation
 */

(function() {
  'use strict';

  /**
   * Initialize milestone expand/collapse functionality
   */
  function initMilestoneToggle() {
    const milestoneCards = document.querySelector('.milestone-cards');
    const viewAllLink = document.querySelector('.milestones-header a[href="#"]');

    if (!milestoneCards || !viewAllLink) return;

    // Handle View All click
    viewAllLink.addEventListener('click', (e) => {
      e.preventDefault();
      
      if (milestoneCards.classList.contains('collapsed')) {
        milestoneCards.classList.remove('collapsed');
        milestoneCards.classList.add('expanded');
        viewAllLink.textContent = 'View Less';
      } else {
        milestoneCards.classList.remove('expanded');
        milestoneContainerCollapsedCheck(milestoneCards);
        viewAllLink.textContent = 'View All';
      }
    });

    function milestoneContainerCollapsedCheck(container) {
      container.classList.add('collapsed');
    }
  }

  /**
   * Initialize carousel navigation
   */
  function initCarousel() {
    const timelineContainer = document.getElementById('milestoneTimelineContainer');
    if (!timelineContainer) return;

    const prevBtn = document.getElementById('milestonePrevBtn');
    const nextBtn = document.getElementById('milestoneNextBtn');

    if (!prevBtn || !nextBtn) return;

    const SCROLL_AMOUNT = 340;

    function scrollCarousel(amount) {
      timelineContainer.scrollBy({
        left: amount,
        behavior: 'smooth'
      });
    }

    function updateButtons() {
      const scrollLeft = timelineContainer.scrollLeft;
      const scrollWidth = timelineContainer.scrollWidth;
      const clientWidth = timelineContainer.clientWidth;

      prevBtn.disabled = scrollLeft <= 0;
      nextBtn.disabled = scrollLeft + clientWidth >= scrollWidth - 5;
    }

    // Attach click handlers
    prevBtn.addEventListener('click', () => scrollCarousel(-SCROLL_AMOUNT));
    nextBtn.addEventListener('click', () => scrollCarousel(SCROLL_AMOUNT));

    // Update button states
    updateButtons();
    timelineContainer.addEventListener('scroll', updateButtons);

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
      if (!timelineContainer || timelineContainer.offsetParent === null) return;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        scrollCarousel(-SCROLL_AMOUNT);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        scrollCarousel(SCROLL_AMOUNT);
      }
    });

    // Touch/swipe support
    let touchStartX = 0;
    let touchEndX = 0;

    timelineContainer.addEventListener('touchstart', (e) => {
      touchStartX = e.changedTouches[0].screenX;
    }, false);

    timelineContainer.addEventListener('touchend', (e) => {
      touchEndX = e.changedTouches[0].screenX;
      const swipeThreshold = 50;
      const diff = touchStartX - touchEndX;

      if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
          scrollCarousel(SCROLL_AMOUNT);
        } else {
          scrollCarousel(-SCROLL_AMOUNT);
        }
      }
    }, false);

    // Update on content changes
    const observer = new MutationObserver(() => {
      setTimeout(updateButtons, 100);
    });

    observer.observe(timelineContainer, {
      childList: true,
      subtree: true,
      attributes: true
    });
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initMilestoneToggle();
      initCarousel();
    });
  } else {
    initMilestoneToggle();
    initCarousel();
  }
})();
