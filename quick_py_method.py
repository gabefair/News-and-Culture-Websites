import sys, os, subprocess, time, random

BROWSER_PATH = ""

def launchBrowser(links_string):
    command = []
    command.append(BROWSER_PATH)
    subprocess.run(command + links_string)
    random_number = random.randint(10, 75)
    print("Sleeping: " + str(random_number))
    time.sleep(random_number)
    
    # if random_number > 65:
    #     time.sleep(random_number//2)
    #     command = ['taskkill',r'/IM','firefox.exe']
    #     subprocess.run(command)
    #     time.sleep(random_number//10)
    #     command = ['taskkill',r'/IM','firefox.exe',r'/F']
    #     time.sleep(random_number//10)


def readLinks(path_to_input_links, current_chunk_size):
    with open(path_to_input_links, encoding="utf8") as fp:
        lines = fp.readlines()
        random.shuffle(lines)
        lines = [line.strip() for line in lines]
        list_of_links = [lines[i:i+current_chunk_size] for i in range(0, len(lines), current_chunk_size)]
    
    return list_of_links

def process_links(list_of_links):
    for index, links in enumerate(list_of_links):
        #links_string = " ".join(links).strip()
        links_string = links
        print(str(index) + " of " + str(len(list_of_links)) + " at: "+ str(links_string))
        launchBrowser(links_string)

def main():
    global BROWSER_PATH
    BROWSER_PATH = '%r'%sys.argv[1]
    BROWSER_PATH = BROWSER_PATH[1:-1]
    path_to_input_links = '%r'%sys.argv[2]
    path_to_input_links = path_to_input_links[1:-1]
    current_chunk_size = int(sys.argv[3])
    list_of_links = readLinks(path_to_input_links, current_chunk_size)
    for x in range(500):
        process_links(list_of_links)

if __name__ == '__main__':
    main()
