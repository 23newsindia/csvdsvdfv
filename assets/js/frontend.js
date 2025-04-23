// Use passive event listeners and IntersectionObserver for better performance
const options = { passive: true };
let carouselObserver;

document.addEventListener('DOMContentLoaded', function() {
    setupIntersectionObserver();
    initImageLoadStates();
    initResponsiveGrids();
}, options);

function setupIntersectionObserver() {
    carouselObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const container = entry.target;
                if (!container.initialized) {
                    handleGridLayout(container);
                    container.initialized = true;
                }
            }
        });
    }, {
        threshold: 0.1
    });
}

function initImageLoadStates() {
    const images = document.querySelectorAll('.cg-category-image');
    if ('loading' in HTMLImageElement.prototype) {
        images.forEach(img => {
            img.loading = 'lazy';
            if (img.complete) {
                img.classList.add('loaded');
            } else {
                img.addEventListener('load', () => img.classList.add('loaded'), options);
                img.addEventListener('error', () => console.error('Image failed to load:', img.src), options);
            }
        });
    } else {
        // Fallback for browsers that don't support native lazy loading
        const imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.classList.add('loaded');
                    imageObserver.unobserve(img);
                }
            });
        });
        images.forEach(img => imageObserver.observe(img));
    }
}

function initResponsiveGrids() {
    const grids = document.querySelectorAll('.cg-grid-container[data-carousel="true"]');
    grids.forEach(grid => {
        carouselObserver.observe(grid);
        
        // Debounced resize handler using requestAnimationFrame
        let rafId;
        window.addEventListener('resize', () => {
            cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(() => handleGridLayout(grid));
        }, options);
    });
}

const isMobile = () => window.innerWidth <= 767;

function handleGridLayout(container) {
    if (!container) return;
    
    const isCarouselEnabled = container.dataset.carousel === 'true';
    container.classList.remove('cg-carousel-mode', 'cg-grid-mode');
    
    if (isMobile() && isCarouselEnabled) {
        initCarousel(container);
    } else {
        initGridLayout(container);
    }
}

function initGridLayout(container) {
    container.classList.add('cg-grid-mode');
    const columns = isMobile() 
        ? parseInt(container.dataset.mobileColumns) || 2
        : parseInt(container.dataset.columns) || 3;
    
    container.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
    container.querySelectorAll('.cg-bx').forEach(item => item.style.display = '');
}

// Use WeakMap to store carousel instance data
const carouselData = new WeakMap();

function initCarousel(container) {
    container.classList.add('cg-carousel-mode');
    
    const items = Array.from(container.querySelectorAll('.cg-bx'));
    const mobileColumns = parseInt(container.dataset.mobileColumns) || 2;
    const itemsPerSlide = mobileColumns * 2;
    
    // Create and cache carousel structure
    const carouselInner = document.createElement('div');
    carouselInner.className = 'cg-carousel-inner';
    
    // Use DocumentFragment for better performance
    const fragment = document.createDocumentFragment();
    
    // Group items into slides
    for (let i = 0; i < items.length; i += itemsPerSlide) {
        const slide = document.createElement('div');
        slide.className = 'cg-carousel-slide';
        slide.setAttribute('data-slide-index', Math.floor(i / itemsPerSlide));
        
        items.slice(i, i + itemsPerSlide).forEach(item => {
            const clone = item.cloneNode(true);
            clone.style.width = `${100 / mobileColumns}%`;
            slide.appendChild(clone);
        });
        
        fragment.appendChild(slide);
    }
    
    carouselInner.appendChild(fragment);
    container.innerHTML = '';
    container.appendChild(carouselInner);
    
    const slides = carouselInner.querySelectorAll('.cg-carousel-slide');
    if (slides.length > 1) {
        addCarouselDots(container, slides.length);
    }
    
    setupTouchControls(container, carouselInner);
    initImageLoadStates();
}

function addCarouselDots(container, slideCount) {
    const fragment = document.createDocumentFragment();
    const dotsContainer = document.createElement('div');
    dotsContainer.className = 'cg-carousel-dots';
    
    for (let i = 0; i < slideCount; i++) {
        const dot = document.createElement('button');
        dot.className = `cg-carousel-dot ${i === 0 ? 'active' : ''}`;
        dot.setAttribute('aria-label', `Go to slide ${i + 1}`);
        dot.setAttribute('data-slide-index', i);
        fragment.appendChild(dot);
    }
    
    dotsContainer.appendChild(fragment);
    container.appendChild(dotsContainer);
    
    dotsContainer.addEventListener('click', (e) => {
        const dot = e.target.closest('.cg-carousel-dot');
        if (dot) {
            goToSlide(container, parseInt(dot.dataset.slideIndex));
        }
    }, options);
}

function setupTouchControls(container, carouselInner) {
    const data = {
        startX: 0,
        currentTranslate: 0,
        prevTranslate: 0,
        isDragging: false,
        currentIndex: 0,
        animationID: 0,
        startTime: 0,
        slideWidth: carouselInner.offsetWidth,
        maxIndex: carouselInner.children.length - 1
    };
    
    carouselData.set(container, data);
    
    const touchStart = (e) => {
        const touch = e.type === 'touchstart' ? e.touches[0] : e;
        data.startX = touch.clientX;
        data.startTime = Date.now();
        data.isDragging = true;
        
        data.animationID = requestAnimationFrame(() => animation(container, carouselInner));
        carouselInner.style.transition = 'none';
    };
    
    const touchMove = (e) => {
        if (!data.isDragging) return;
        
        const touch = e.type === 'touchmove' ? e.touches[0] : e;
        const currentX = touch.clientX;
        const diff = currentX - data.startX;
        data.currentTranslate = Math.max(
            Math.min(data.prevTranslate + diff, 0),
            -(data.maxIndex * data.slideWidth)
        );
    };
    
    const touchEnd = () => {
        data.isDragging = false;
        cancelAnimationFrame(data.animationID);
        
        const movedBy = data.currentTranslate - data.prevTranslate;
        const timeTaken = Date.now() - data.startTime;
        const velocity = Math.abs(movedBy) / timeTaken;
        
        if (Math.abs(movedBy) > data.slideWidth / 3 || velocity > 0.5) {
            if (movedBy < 0 && data.currentIndex < data.maxIndex) {
                data.currentIndex++;
            } else if (movedBy > 0 && data.currentIndex > 0) {
                data.currentIndex--;
            }
        }
        
        goToSlide(container, data.currentIndex);
        data.prevTranslate = -(data.currentIndex * data.slideWidth);
        data.currentTranslate = data.prevTranslate;
    };
    
    container.addEventListener('touchstart', touchStart, options);
    container.addEventListener('touchmove', touchMove, options);
    container.addEventListener('touchend', touchEnd, options);
    container.addEventListener('touchcancel', touchEnd, options);
}

function animation(container, element) {
    const data = carouselData.get(container);
    if (data.isDragging) {
        setSlidePosition(element, data.currentTranslate);
        requestAnimationFrame(() => animation(container, element));
    }
}

function setSlidePosition(element, translate) {
    element.style.transform = `translate3d(${translate}px, 0, 0)`;
}

function goToSlide(container, index) {
    const data = carouselData.get(container);
    const carouselInner = container.querySelector('.cg-carousel-inner');
    
    carouselInner.style.transition = 'transform 0.3s ease-out';
    setSlidePosition(carouselInner, -(index * data.slideWidth));
    
    container.querySelectorAll('.cg-carousel-dot').forEach((dot, i) => {
        dot.classList.toggle('active', i === index);
    });
}