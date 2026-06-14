/**
 * Carousel Navigation Module
 * Handles smooth scrolling navigation for milestone timeline carousel
 */

(function() {
  'use strict';

  const SCROLL_AMOUNT = 340; // Approximate card width + gap
  const DEBOUNCE_TIME = 100;

  function initCarousel() {
    const timelineContainer = document.getElementById('milestoneTimelineContainer');
    if (!timelineContainer) return;

    const prevBtn = document.getElementById('milestonePrevBtn');
    const nextBtn = document.getElementById('milestoneNextBtn');

    if (!prevBtn || !nextBtn) return;

    // Attach click handlers
    if (prevBtn) prevBtn.addEventListener('click', () => scrollCarousel(timelineContainer, -SCROLL_AMOUNT));
    if (nextBtn) nextBtn.addEventListener('click', () => scrollCarousel(timelineContainer, SCROLL_AMOUNT));

    // Update button states on load and scroll
    updateCarouselButtons(timelineContainer, prevBtn, nextBtn);
    timelineContainer.addEventListener('scroll', () => {
      updateCarouselButtons(timelineContainer, prevBtn, nextBtn);
    });

    // Support keyboard navigation (Arrow keys)
    document.addEventListener('keydown', (e) => {
      if (!timelineContainer || timelineContainer.offsetParent === null) return;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        scrollCarousel(timelineContainer, -SCROLL_AMOUNT);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        scrollCarousel(timelineContainer, SCROLL_AMOUNT);
      }
    });

    // Handle touch/swipe on the timeline
    let touchStartX = 0;
    let touchEndX = 0;

    timelineContainer.addEventListener('touchstart', (e) => {
      touchStartX = e.changedTouches[0].screenX;
    }, false);

    timelineContainer.addEventListener('touchend', (e) => {
      touchEndX = e.changedTouches[0].screenX;
      handleSwipe(timelineContainer, prevBtn, nextBtn);
    }, false);

    function handleSwipe(container, prev, next) {
      const swipeThreshold = 50;
      const diff = touchStartX - touchEndX;

      if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
          // Swiped left, scroll right
          scrollCarousel(container, SCROLL_AMOUNT);
        } else {
          // Swiped right, scroll left
          scrollCarousel(container, -SCROLL_AMOUNT);
        }
      }
    }
  }

  function scrollCarousel(container, amount) {
    container.scrollBy({
      left: amount,
      behavior: 'smooth'
    });
  }

  function updateCarouselButtons(container, prevBtn, nextBtn) {
    if (!container || !prevBtn || !nextBtn) return;

    const scrollLeft = container.scrollLeft;
    const scrollWidth = container.scrollWidth;
    const clientWidth = container.clientWidth;

    // Disable prev button at start
    prevBtn.disabled = scrollLeft <= 0;

    // Disable next button at end
    nextBtn.disabled = scrollLeft + clientWidth >= scrollWidth - 5; // 5px tolerance
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCarousel);
  } else {
    initCarousel();
  }

  // Re-initialize after new milestones are loaded (for mutation detection)
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.type === 'childList' || mutation.type === 'attributes') {
        const container = document.getElementById('milestoneTimelineContainer');
        const prevBtn = document.getElementById('milestonePrevBtn');
        const nextBtn = document.getElementById('milestoneNextBtn');
        if (container && prevBtn && nextBtn) {
          setTimeout(() => updateCarouselButtons(container, prevBtn, nextBtn), 100);
        }
      }
    });
  });

  const timelineContainer = document.getElementById('milestoneTimelineContainer');
  if (timelineContainer) {
    observer.observe(timelineContainer, {
      childList: true,
      subtree: true,
      attributes: true
    });
  }
})();
